// Proven 2026-10-07 (audit packet R9): `go vet ./...` clean and
// `go test ./...` green in-sandbox on go1.27.1 AND CI-exact go1.22.12.
// The `go` CI job still flips to required only after a green CI run
// (see docs/TEST_EVIDENCE.md graduation log).
package middleware

import (
    "bytes"
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "fmt"
    "io"
    "net/http"
    "strconv"
    "strings"
    "time"
)

// serviceAuthToleranceSeconds mirrors Laravel's SERVICE_AUTH_TOLERANCE
// default: ServiceAuthenticator::verifyRequest rejects timestamps outside
// +-300 seconds.
const serviceAuthToleranceSeconds = 300

// ServiceAuth verifies Laravel service signatures on every request whose
// path does not start with one of openPrefixes (R9).
//
// Header contract (mirrors ServiceAuthenticator::generateHeaders):
//
//   X-Service-ID  presented caller id; required present but not matched —
//                 the daemon holds a single shared secret, so the HMAC key
//                 (not the id) is what authenticates.
//   X-Timestamp   unix seconds; must be within +-300s of now.
//   X-Nonce       unique per request; required present (replay tracking is
//                 future work — the timestamp window plus the idempotency
//                 layer bound replays in the meantime).
//   X-Signature   hex HMAC-SHA256 over "METHOD:path:raw-body:timestamp:nonce"
//                 (see VerifyServiceSignature).
//
// An empty secret disables verification (dev-open). Production boot refuses
// empty secrets (ValidateStrength), and when the process knows it is
// production the middleware fails closed even so.
func ServiceAuth(secret string, isProduction bool, openPrefixes ...string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
            for _, prefix := range openPrefixes {
                if strings.HasPrefix(r.URL.Path, prefix) {
                    next.ServeHTTP(w, r)
                    return
                }
            }
            if secret == "" {
                if isProduction {
                    writeServiceAuthError(w, "service_auth_not_configured")
                    return
                }
                next.ServeHTTP(w, r)
                return
            }
            body, err := io.ReadAll(r.Body)
            if err != nil {
                w.WriteHeader(http.StatusBadRequest)
                return
            }
            r.Body = io.NopCloser(bytes.NewBuffer(body))
            err = VerifyServiceSignature(
                secret,
                r.Method,
                r.URL.Path,
                body,
                r.Header.Get("X-Timestamp"),
                r.Header.Get("X-Nonce"),
                r.Header.Get("X-Service-ID"),
                r.Header.Get("X-Signature"),
                time.Now().Unix(),
            )
            if err != nil {
                writeServiceAuthError(w, "invalid_signature")
                return
            }
            next.ServeHTTP(w, r)
        })
    }
}

// VerifyServiceSignature mirrors Laravel's
// App\\Services\\Integration\\ServiceAuthenticator::verifyRequest exactly.
// now is unix seconds (a parameter so tests need no clock).
func VerifyServiceSignature(secret, method, path string, body []byte, timestamp, nonce, serviceID, signature string, now int64) error {
    if serviceID == "" || timestamp == "" || nonce == "" || signature == "" {
        return fmt.Errorf("missing service auth headers")
    }
    ts, err := strconv.ParseInt(timestamp, 10, 64)
    if err != nil {
        return fmt.Errorf("invalid timestamp: %s", timestamp)
    }
    if diff := now - ts; diff > serviceAuthToleranceSeconds || diff < -serviceAuthToleranceSeconds {
        return fmt.Errorf("timestamp out of tolerance")
    }
    // Message shape is "METHOD:path:body:timestamp:nonce" — path with a
    // leading slash and no query string (r.URL.Path, like Laravel's
    // $request->path()), raw body bytes, timestamp re-encoded from the
    // parsed int so this matches sprintf('%d') exactly.
    message := method + ":" + path + ":" + string(body) + ":" + strconv.FormatInt(ts, 10) + ":" + nonce
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write([]byte(message))
    expected := hex.EncodeToString(mac.Sum(nil))
    if !hmac.Equal([]byte(expected), []byte(signature)) {
        return fmt.Errorf("invalid service signature")
    }
    return nil
}

func writeServiceAuthError(w http.ResponseWriter, code string) {
    w.Header().Set("Content-Type", "application/json")
    w.WriteHeader(http.StatusUnauthorized)
    w.Write([]byte(`{"error":"` + code + `"}`))
}
