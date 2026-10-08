// Proven 2026-10-07 (audit packet R9): `go vet ./...` clean and
// `go test ./...` green in-sandbox on go1.27.1 AND CI-exact go1.22.12.
// The `go` CI job still flips to required only after a green CI run
// (see docs/TEST_EVIDENCE.md graduation log).
package config

import (
    "strings"
    "testing"
)

// strongSecret is a 64-char value with no weak substrings.
func strongSecret() string {
    return strings.Repeat("9f2c4a7e", 8)
}

func managerFor(env, paymentEnv, jwt, hmac, webhook string) *SecretsManager {
    return NewSecretsManager(&Config{
        Env:           env,
        PaymentEnv:    paymentEnv,
        JWTSecret:     jwt,
        HMACSecret:    hmac,
        WebhookSecret: webhook,
    })
}

func TestValidateStrengthDevAllowsEmpty(t *testing.T) {
    m := managerFor("development", "sandbox", "", "", "")
    if err := m.ValidateStrength(); err != nil {
        t.Fatalf("dev should allow empty secrets, got %v", err)
    }
}

func TestValidateStrengthProdRejectsEmpty(t *testing.T) {
    for _, tc := range []struct {
        name    string
        jwt     string
        hmac    string
        webhook string
        want    string
    } {
        {"empty JWT", "", strongSecret(), strongSecret(), "JWT secret must be set in production"},
        {"empty HMAC", strongSecret(), "", strongSecret(), "HMAC secret must be set in production"},
        {"empty webhook", strongSecret(), strongSecret(), "", "Webhook secret must be set in production"},
    } {
        t.Run(tc.name, func(t *testing.T) {
            m := managerFor("production", "sandbox", tc.jwt, tc.hmac, tc.webhook)
            err := m.ValidateStrength()
            if err == nil || err.Error() != tc.want {
                t.Fatalf("got %v, want %q", err, tc.want)
            }
        })
    }
}

func TestValidateStrengthPaymentEnvCountsAsProduction(t *testing.T) {
    // Deliberate strictness: a production payment environment refuses empty
    // secrets even when APP_ENV itself is not production.
    m := managerFor("development", "production", "", strongSecret(), strongSecret())
    if err := m.ValidateStrength(); err == nil {
        t.Fatal("payment-env production should refuse empty JWT secret")
    }
}

func TestValidateStrengthRejectsShort(t *testing.T) {
    // The old compose fallbacks are 20/21/24 chars: still rejected.
    for _, tc := range []struct {
        name    string
        jwt     string
        hmac    string
        webhook string
    } {
        {"short JWT", "change-me-jwt-secret", strongSecret(), strongSecret()},
        {"short HMAC", strongSecret(), "change-me-hmac-secret", strongSecret()},
        {"short webhook", strongSecret(), strongSecret(), "change-me-webhook-secret"},
    } {
        t.Run(tc.name, func(t *testing.T) {
            m := managerFor("development", "sandbox", tc.jwt, tc.hmac, tc.webhook)
            if err := m.ValidateStrength(); err == nil {
                t.Fatal("short secret should be rejected even in dev")
            }
        })
    }
}

func TestValidateStrengthRejectsPlaceholders(t *testing.T) {
    // Placeholder-shaped values at valid length must still fail (substring
    // screen — an exact match could never fire past the 32-char minimum).
    longChangeMe := "change-me-jwt-secret-00000000000000"
    if len(longChangeMe) < 32 {
        t.Fatal("test value must be long enough to isolate the weak screen")
    }
    m := managerFor("development", "sandbox", longChangeMe, strongSecret(), strongSecret())
    err := m.ValidateStrength()
    if err == nil || !strings.Contains(err.Error(), "weak word") {
        t.Fatalf("placeholder secret should be rejected, got %v", err)
    }
}

func TestValidateStrengthAcceptsStrong(t *testing.T) {
    m := managerFor("production", "production", strongSecret(), strongSecret(), strongSecret())
    if err := m.ValidateStrength(); err != nil {
        t.Fatalf("strong secrets should pass, got %v", err)
    }
}

func TestValidateSecretStrengthStandalone(t *testing.T) {
    if err := ValidateSecretStrength("", "X"); err != nil {
        t.Fatalf("standalone allows empty (caller gates production), got %v", err)
    }
    if err := ValidateSecretStrength("short", "X"); err == nil {
        t.Fatal("short secret should fail")
    }
    if err := ValidateSecretStrength(strongSecret(), "X"); err != nil {
        t.Fatalf("strong secret should pass, got %v", err)
    }
}
