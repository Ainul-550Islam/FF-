package config

import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "fmt"
    "strings"
)

type SecretsManager struct {
    jwtSecret     string
    webhookSecret string
    hmacSecret    string
    production    bool
}

func NewSecretsManager(cfg *Config) *SecretsManager {
    return &SecretsManager{jwtSecret: cfg.JWTSecret, webhookSecret: cfg.WebhookSecret, hmacSecret: cfg.HMACSecret, production: cfg.IsProduction()}
}

func (s *SecretsManager) ValidateStrength() error {
    for _, item := range []struct {
        secret string
        name   string
    }{
        {s.jwtSecret, "JWT secret"},
        {s.hmacSecret, "HMAC secret"},
        {s.webhookSecret, "Webhook secret"},
    } {
        if item.secret == "" {
            // Empty is dev-open. Production refuses: an empty key voids
            // every HMAC verified against it, and the webhook path accepts
            // unsigned traffic when no secret is configured (R9).
            if s.production {
                return fmt.Errorf("%s must be set in production", item.name)
            }
            continue
        }
        if err := ValidateSecretStrength(item.secret, item.name); err != nil {
            return err
        }
    }
    return nil
}

func (s *SecretsManager) Redact(input string) string {
    if input == "" {
        return ""
    }
    for _, secret := range []string{s.jwtSecret, s.webhookSecret, s.hmacSecret} {
        if secret != "" && len(secret) > 4 {
            input = strings.ReplaceAll(input, secret, "***REDACTED***")
        }
    }
    return input
}

func SecureCompare(a, b string) bool { return hmac.Equal([]byte(a), []byte(b)) }

func RedactSensitiveData(data map[string]interface{}) map[string]interface{} {
    sensitive := []string{"password", "secret", "token", "jwt", "api_key", "private_key", "DATABASE_URL", "REDIS_URL"}
    result := make(map[string]interface{})
    for k, v := range data {
        lower := strings.ToLower(k)
        redacted := false
        for _, s := range sensitive {
            if strings.Contains(lower, s) {
                result[k] = "***REDACTED***"
                redacted = true
                break
            }
        }
        if !redacted {
            if m, ok := v.(map[string]interface{}); ok {
                result[k] = RedactSensitiveData(m)
            } else {
                result[k] = v
            }
        }
    }
    return result
}

func GenerateHMAC(secret, message string) string {
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write([]byte(message))
    return hex.EncodeToString(mac.Sum(nil))
}

func ValidateSecretStrength(secret, name string) error {
    if secret == "" {
        return nil // Empty allowed in dev, but should be set in production
    }
    if len(secret) < 32 {
        return fmt.Errorf("%s must be at least 32 chars for security, got %d", name, len(secret))
    }
    // Reject placeholder-shaped secrets. Substring (not exact) match: every
    // exact word is shorter than the 32-char minimum above, so an exact match
    // after the length check could never fire. Case-insensitive; a random
    // value that trips this should simply be regenerated.
    weak := []string{"secret", "password", "123456", "test", "default", "changeme", "change-me", "placeholder"}
    lower := strings.ToLower(secret)
    for _, w := range weak {
        if strings.Contains(lower, w) {
            return fmt.Errorf("%s contains weak word '%s', generate a random value", name, w)
        }
    }
    return nil
}
