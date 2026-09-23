package config

import (
    "fmt"
    "os"
    "strconv"
    "strings"

    "github.com/ffarena/payment-gateway-go/internal/providers"
)

type Config struct {
    Port              int                               `json:"port"`
    Env               string                            `json:"env"`
    ServiceID         string                            `json:"service_id"`
    DatabaseURL       string                            `json:"-"`
    DBDriver          string                            `json:"db_driver"`
    RedisURL          string                            `json:"-"`
    JWTSecret         string                            `json:"-"`
    WebhookSecret     string                            `json:"-"`
    HMACSecret        string                            `json:"-"`
    RateLimitPerMin   int                               `json:"rate_limit_per_min"`
    EnableMetrics     bool                              `json:"enable_metrics"`
    LogLevel          string                            `json:"log_level"`
    Version           string                            `json:"version"`
    PaymentEnv        string                            `json:"payment_env"`
    ProviderConfigs   map[string]providers.ProviderConfig `json:"-"`
    TokenEncryptionKey string                           `json:"-"`
    CORSAllowedOrigins string                           `json:"cors_allowed_origins"`
    JaegerEndpoint    string                            `json:"-"`
}

func Load() (*Config, error) {
    cfg := &Config{
        Port:               getEnvInt("PORT", 8081),
        Env:                getEnv("APP_ENV", "production"),
        ServiceID:          getEnv("SERVICE_ID", "payment-gateway-go"),
        DatabaseURL:        getEnv("DATABASE_URL", ""),
        DBDriver:           getEnv("DB_DRIVER", "postgres"),
        RedisURL:           getEnv("REDIS_URL", ""),
        JWTSecret:          getEnv("JWT_SECRET", ""),
        WebhookSecret:      getEnv("WEBHOOK_SECRET", ""),
        HMACSecret:         getEnv("SERVICE_HMAC_SECRET", ""),
        RateLimitPerMin:    getEnvInt("RATE_LIMIT_PER_MIN", 60),
        EnableMetrics:      getEnvBool("ENABLE_METRICS", true),
        LogLevel:           getEnv("LOG_LEVEL", "info"),
        Version:            getEnv("VERSION", "1.0.0"),
        PaymentEnv:         getEnv("PAYMENT_ENV", "sandbox"),
        TokenEncryptionKey: getEnv("TOKEN_ENCRYPTION_KEY", ""),
        CORSAllowedOrigins: getEnv("CORS_ALLOWED_ORIGINS", "https://ffarena.com,https://www.ffarena.com,https://api.ffarena.com,https://payment.ffarena.com"),
        JaegerEndpoint:     getEnv("JAEGER_ENDPOINT", ""),
    }

    cfg.ProviderConfigs = loadProviderConfigs()

    if err := cfg.Validate(); err != nil {
        return nil, err
    }
    return cfg, nil
}

func loadProviderConfigs() map[string]providers.ProviderConfig {
    configs := make(map[string]providers.ProviderConfig)

    bkashEnabled := getEnvBool("PAYMENT_BKASH_ENABLED", getEnvBool("PAYMENT_PROVIDER_BKASH_ENABLED", false))
    bkashBaseURL := getEnv("PAYMENT_BKASH_BASE_URL", getEnv("BKASH_TOKENIZE_BASE_URL", "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout"))
    if getEnv("BKASH_TOKENIZE_SANDBOX", "true") == "false" {
        bkashBaseURL = getEnv("PAYMENT_BKASH_BASE_URL", "https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout")
    }
    configs["bkash"] = providers.ProviderConfig{
        BaseURL:     bkashBaseURL,
        AppKey:      getEnv("PAYMENT_BKASH_APP_KEY", getEnv("BKASH_TOKENIZE_APP_KEY", "")),
        AppSecret:   getEnv("PAYMENT_BKASH_APP_SECRET", getEnv("BKASH_TOKENIZE_APP_SECRET", "")),
        Username:    getEnv("PAYMENT_BKASH_USERNAME", getEnv("BKASH_TOKENIZE_USER_NAME", "")),
        Password:    getEnv("PAYMENT_BKASH_PASSWORD", getEnv("BKASH_TOKENIZE_PASSWORD", "")),
        Secret:      getEnv("PAYMENT_BKASH_SECRET", ""),
        TimeoutMs:   getEnvInt("PAYMENT_BKASH_TIMEOUT", 15000),
        Sandbox:     getEnv("PAYMENT_ENV", "sandbox") == "sandbox",
        Enabled:     bkashEnabled,
        CallbackURL: getEnv("PAYMENT_BKASH_CALLBACK_URL", getEnv("BKASH_CALLBACK_URL", "")),
    }

    nagadEnabled := getEnvBool("PAYMENT_NAGAD_ENABLED", getEnvBool("PAYMENT_PROVIDER_NAGAD_ENABLED", false))
    nagadBaseURL := getEnv("PAYMENT_NAGAD_BASE_URL", getEnv("NAGAD_BASE_URL", "http://sandbox.mynagad.com:10060"))
    configs["nagad"] = providers.ProviderConfig{
        BaseURL:        nagadBaseURL,
        MerchantID:     getEnv("PAYMENT_NAGAD_MERCHANT_ID", getEnv("NAGAD_MERCHANT_ID", "")),
        MerchantNumber: getEnv("PAYMENT_NAGAD_MERCHANT_NUMBER", getEnv("NAGAD_MERCHANT_NUMBER", "")),
        PrivateKey:     getEnv("PAYMENT_NAGAD_PRIVATE_KEY", getEnv("NAGAD_PRIVATE_KEY", "")),
        PublicKey:      getEnv("PAYMENT_NAGAD_PUBLIC_KEY", getEnv("NAGAD_PUBLIC_KEY", "")),
        Secret:         getEnv("PAYMENT_NAGAD_SECRET", ""),
        TimeoutMs:      getEnvInt("PAYMENT_NAGAD_TIMEOUT", 20000),
        Sandbox:        getEnv("PAYMENT_ENV", "sandbox") == "sandbox",
        Enabled:        nagadEnabled,
        CallbackURL:    getEnv("PAYMENT_NAGAD_CALLBACK_URL", getEnv("NAGAD_CALLBACK_URL", "")),
    }

    rocketEnabled := getEnvBool("PAYMENT_ROCKET_ENABLED", getEnvBool("PAYMENT_PROVIDER_ROCKET_ENABLED", false))
    rocketBaseURL := getEnv("PAYMENT_ROCKET_BASE_URL", getEnv("ROCKET_BASE_URL", ""))
    configs["rocket"] = providers.ProviderConfig{
        BaseURL:    rocketBaseURL,
        MerchantID: getEnv("PAYMENT_ROCKET_MERCHANT_ID", getEnv("ROCKET_MERCHANT_ID", "")),
        Secret:     getEnv("PAYMENT_ROCKET_SECRET", ""),
        TimeoutMs:  getEnvInt("PAYMENT_ROCKET_TIMEOUT", 15000),
        Sandbox:    getEnv("PAYMENT_ENV", "sandbox") == "sandbox",
        Enabled:    rocketEnabled,
    }

    manualEnabled := getEnvBool("PAYMENT_MANUAL_ENABLED", true)
    configs["manual"] = providers.ProviderConfig{
        BaseURL:   "",
        Secret:    getEnv("PAYMENT_MANUAL_SECRET", ""),
        TimeoutMs: 5000,
        Sandbox:   true,
        Enabled:   manualEnabled,
    }

    return configs
}

func (c *Config) Validate() error {
    if c.Port <= 0 || c.Port > 65535 {
        return fmt.Errorf("invalid port %d", c.Port)
    }
    if err := c.ValidatePaymentEnv(); err != nil {
        return err
    }
    return nil
}

func (c *Config) ValidatePaymentEnv() error {
    validEnvs := []string{"development", "testing", "sandbox", "staging", "production"}
    found := false
    for _, env := range validEnvs {
        if c.PaymentEnv == env {
            found = true
            break
        }
    }
    if !found {
        return fmt.Errorf("invalid PAYMENT_ENV %s, must be one of %v", c.PaymentEnv, validEnvs)
    }

    if c.Env == "testing" || c.Env == "test" {
        for provider, cfg := range c.ProviderConfigs {
            if cfg.BaseURL != "" {
                if strings.Contains(cfg.BaseURL, "pay.bka.sh") && !strings.Contains(cfg.BaseURL, "sandbox") {
                    if c.PaymentEnv != "production" {
                        return fmt.Errorf("safety guard: test environment with production endpoint for %s: %s - FAIL", provider, cfg.BaseURL)
                    }
                }
                if strings.Contains(cfg.BaseURL, "api.mynagad.com") && !strings.Contains(cfg.BaseURL, "sandbox") {
                    if c.PaymentEnv == "sandbox" || c.Env == "testing" {
                        if c.PaymentEnv != "production" {
                            return fmt.Errorf("safety guard: sandbox env with production endpoint for %s", provider)
                        }
                    }
                }
            }
        }
    }

    return nil
}

func (c *Config) IsProduction() bool {
    return c.PaymentEnv == "production" || c.Env == "production"
}

func (c *Config) IsSandbox() bool {
    return c.PaymentEnv == "sandbox"
}

func (c *Config) IsDevelopment() bool {
    return c.Env == "development" || c.Env == "local"
}

func (c *Config) Redacted() *Config {
    clone := *c
    clone.DatabaseURL = redactURL(clone.DatabaseURL)
    clone.RedisURL = redactURL(clone.RedisURL)
    clone.JWTSecret = "***REDACTED***"
    clone.WebhookSecret = "***REDACTED***"
    clone.HMACSecret = "***REDACTED***"
    clone.TokenEncryptionKey = "***REDACTED***"
    clone.JaegerEndpoint = "***REDACTED***"
    redactedProviders := make(map[string]providers.ProviderConfig)
    for k, v := range c.ProviderConfigs {
        redacted := v
        redacted.Secret = "***REDACTED***"
        redacted.AppKey = "***REDACTED***"
        redacted.AppSecret = "***REDACTED***"
        redacted.Username = "***REDACTED***"
        redacted.Password = "***REDACTED***"
        redacted.PrivateKey = "***REDACTED***"
        redacted.PublicKey = "***REDACTED***"
        redacted.APIKey = "***REDACTED***"
        redactedProviders[k] = redacted
    }
    clone.ProviderConfigs = redactedProviders
    return &clone
}

func redactURL(url string) string {
    if url == "" {
        return ""
    }
    if atPos := strings.Index(url, "@"); atPos != -1 {
        if colonPos := strings.LastIndex(url[:atPos], ":"); colonPos != -1 {
            if slashSlash := strings.Index(url, "://"); slashSlash != -1 {
                if colonPos > slashSlash {
                    return url[:colonPos+1] + "***REDACTED***" + url[atPos:]
                }
            }
        }
    }
    if strings.Contains(strings.ToLower(url), "password") || strings.Contains(strings.ToLower(url), "secret") || strings.Contains(strings.ToLower(url), "token") {
        return "***REDACTED***"
    }
    return url
}

func getEnv(key, defaultValue string) string {
    if value := os.Getenv(key); value != "" {
        return value
    }
    return defaultValue
}

func getEnvInt(key string, defaultValue int) int {
    if value := os.Getenv(key); value != "" {
        if intValue, err := strconv.Atoi(value); err == nil {
            return intValue
        }
    }
    return defaultValue
}

func getEnvBool(key string, defaultValue bool) bool {
    if value := os.Getenv(key); value != "" {
        lower := strings.ToLower(value)
        return lower == "true" || lower == "1" || lower == "yes" || lower == "on"
    }
    return defaultValue
}
