// Proven 2026-10-07 (audit packet R9): `go vet ./...` clean and
// `go test ./...` green in-sandbox on go1.27.1 AND CI-exact go1.22.12.
// The `go` CI job still flips to required only after a green CI run
// (see docs/TEST_EVIDENCE.md graduation log).
package tests

import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "fmt"
    "net/http"
    "net/http/httptest"
    "strings"
    "testing"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/middleware"
)

const serviceAuthTestSecret = "service-auth-test-secret-0123456789ab"

// signServiceRequest mirrors Laravel's ServiceAuthenticator::signRequest:
// hex HMAC-SHA256 over "METHOD:path:body:timestamp:nonce".
func signServiceRequest(secret, method, path, body string, ts int64, nonce string) string {
    message := fmt.Sprintf("%s:%s:%s:%d:%s", method, path, body, ts, nonce)
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write([]byte(message))
    return hex.EncodeToString(mac.Sum(nil))
}

func authedRequest(t *testing.T, method, path, body, secret string, ts int64) *http.Request {
    t.Helper()
    var reader *strings.Reader
    if body == "" {
        reader = strings.NewReader("")
    } else {
        reader = strings.NewReader(body)
    }
    req := httptest.NewRequest(method, path, reader)
    nonce := "test-nonce-0001"
    req.Header.Set("X-Service-ID", "ffarena-laravel")
    req.Header.Set("X-Timestamp", fmt.Sprintf("%d", ts))
    req.Header.Set("X-Nonce", nonce)
    req.Header.Set("X-Signature", signServiceRequest(secret, method, path, body, ts, nonce))
    return req
}

func serveWithServiceAuth(secret string, isProd bool, req *http.Request) *httptest.ResponseRecorder {
    ok := http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        w.WriteHeader(http.StatusOK)
    })
    rec := httptest.NewRecorder()
    middleware.ServiceAuth(secret, isProd, "/health", "/metrics", "/api/v1/webhooks/")(ok).ServeHTTP(rec, req)
    return rec
}

func TestServiceAuthValidSignaturePasses(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{"amount":100}`, serviceAuthTestSecret, time.Now().Unix())
    if rec := serveWithServiceAuth(serviceAuthTestSecret, true, req); rec.Code != http.StatusOK {
        t.Fatalf("valid signature should pass, got %d (%s)", rec.Code, rec.Body.String())
    }
}

func TestServiceAuthValidGetWithEmptyBodyPasses(t *testing.T) {
    req := authedRequest(t, "GET", "/api/v1/payments/methods", "", serviceAuthTestSecret, time.Now().Unix())
    if rec := serveWithServiceAuth(serviceAuthTestSecret, true, req); rec.Code != http.StatusOK {
        t.Fatalf("valid GET should pass, got %d (%s)", rec.Code, rec.Body.String())
    }
}

func TestServiceAuthUnsignedIs401(t *testing.T) {
    req := httptest.NewRequest("POST", "/api/v1/payments", strings.NewReader(`{}`))
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("unsigned request should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthWrongSecretIs401(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{}`, "wrong-secret", time.Now().Unix())
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("wrong-secret signature should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthTamperedBodyIs401(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{"amount":100}`, serviceAuthTestSecret, time.Now().Unix())
    // Swap the body after signing: the signature must not verify.
    req.Body = httptest.NewRequest("POST", "/", strings.NewReader(`{"amount":99999}`)).Body
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("tampered body should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthWrongPathIs401(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{}`, serviceAuthTestSecret, time.Now().Unix())
    req.URL.Path = "/api/v1/payouts"
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("signature for another path should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthStaleTimestampIs401(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{}`, serviceAuthTestSecret, time.Now().Unix()-301)
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("stale timestamp should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthFutureTimestampIs401(t *testing.T) {
    req := authedRequest(t, "POST", "/api/v1/payments", `{}`, serviceAuthTestSecret, time.Now().Unix()+301)
    rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
    if rec.Code != http.StatusUnauthorized {
        t.Fatalf("far-future timestamp should be 401, got %d", rec.Code)
    }
}

func TestServiceAuthOpenPathsSkipVerification(t *testing.T) {
    for _, path := range []string{"/health", "/health/live", "/health/ready", "/metrics", "/api/v1/webhooks/inbound/bkash"} {
        req := httptest.NewRequest("GET", path, nil)
        rec := serveWithServiceAuth(serviceAuthTestSecret, true, req)
        if rec.Code != http.StatusOK {
            t.Fatalf("open path %s should skip auth, got %d", path, rec.Code)
        }
    }
}

func TestServiceAuthEmptySecretDevOpenProdClosed(t *testing.T) {
    unsigned := func() *http.Request {
        return httptest.NewRequest("POST", "/api/v1/payments", strings.NewReader(`{}`))
    }
    if rec := serveWithServiceAuth("", false, unsigned()); rec.Code != http.StatusOK {
        t.Fatalf("empty secret in dev should pass through, got %d", rec.Code)
    }
    if rec := serveWithServiceAuth("", true, unsigned()); rec.Code != http.StatusUnauthorized {
        t.Fatalf("empty secret in prod should fail closed, got %d", rec.Code)
    }
}
