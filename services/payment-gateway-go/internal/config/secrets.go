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
}

func NewSecretsManager(cfg *Config) *SecretsManager {
    return &SecretsManager{jwtSecret: cfg.JWTSecret, webhookSecret: cfg.WebhookSecret, hmacSecret: cfg.HMACSecret}
}

func (s *SecretsManager) ValidateStrength() error {
    if len(s.jwtSecret) > 0 && len(s.jwtSecret) < 32 {
        return fmt.Errorf("JWT secret must be at least 32 chars, got %d", len(s.jwtSecret))
    }
    if len(s.hmacSecret) > 0 && len(s.hmacSecret) < 32 {
        return fmt.Errorf("HMAC secret must be at least 32 chars, got %d", len(s.hmacSecret))
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
    // Check for common weak secrets
    weak := []string{"secret", "password", "123456", "test", "default"}
    lower := strings.ToLower(secret)
    for _, w := range weak {
        if lower == w {
            return fmt.Errorf("%s is too weak, cannot be '%s'", name, w)
        }
    }
    return nil
}
