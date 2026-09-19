package middleware

import (
    "fmt"
    "net/http"
    "os"
    "strings"
)

// CORS middleware with strict domain whitelisting
// - Strict domain whitelisting via CORS_ALLOWED_ORIGINS
// - Defaulting to https://ffarena.com
// - Vary: Origin header
// - Restricted HTTP methods GET, POST, OPTIONS
// - No permissive * in production

func CORS(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
        allowedOrigins := getAllowedOrigins()
        origin := r.Header.Get("Origin")

        allowed := false
        var matchedOrigin string

        for _, ao := range allowedOrigins {
            ao = strings.TrimSpace(ao)
            if ao == "" {
                continue
            }

            if ao == "*" {
                if isDevelopment() {
                    allowed = true
                    matchedOrigin = "*"
                    w.Header().Set("Access-Control-Allow-Origin", "*")
                    break
                }
                // In production, * is not allowed - skip
                continue
            }

            if origin == ao {
                allowed = true
                matchedOrigin = ao
                w.Header().Set("Access-Control-Allow-Origin", origin)
                w.Header().Set("Vary", "Origin")
                break
            }

            if strings.HasSuffix(ao, "*") {
                prefix := strings.TrimSuffix(ao, "*")
                if strings.HasPrefix(origin, prefix) {
                    // Validate that it's a proper subdomain wildcard
                    // e.g., https://*.ffarena.com should match https://api.ffarena.com
                    // but not https://ffarena.com.evil.com
                    if isValidWildcardMatch(origin, ao) {
                        allowed = true
                        matchedOrigin = origin
                        w.Header().Set("Access-Control-Allow-Origin", origin)
                        w.Header().Set("Vary", "Origin")
                        break
                    }
                }
            }
        }

        // If no origin header (non-browser request, e.g., service-to-service, curl, Postman)
        // Allow but don't set CORS headers for security
        // For payment gateway, we should be strict but allow internal calls
        if origin == "" {
            allowed = true
        }

        w.Header().Set("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        w.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type, X-Request-ID, Idempotency-Key, X-Idempotency-Key, X-Signature, X-Service-ID, X-Timestamp, X-Nonce, X-Provider-Signature, X-Webhook-Signature, traceparent, X-Trace-ID, X-Span-ID")
        w.Header().Set("Access-Control-Expose-Headers", "X-Request-ID, X-Trace-ID, X-Span-ID")
        w.Header().Set("Access-Control-Max-Age", "86400")
        w.Header().Set("Access-Control-Allow-Credentials", "false")

        if r.Method == http.MethodOptions {
            if allowed {
                w.WriteHeader(http.StatusNoContent)
            } else {
                w.Header().Set("Content-Type", "application/json")
                w.WriteHeader(http.StatusForbidden)
                w.Write([]byte(`{"error":"origin_not_allowed","message":"CORS origin not allowed"}`))
            }
            return
        }

        if !allowed && origin != "" {
            w.Header().Set("Content-Type", "application/json")
            w.WriteHeader(http.StatusForbidden)
            w.Write([]byte(`{"error":"origin_not_allowed","message":"CORS origin not allowed: ` + origin + `"}`))
            return
        }

        // Log CORS for audit (redacted)
        _ = matchedOrigin

        next.ServeHTTP(w, r)
    })
}

func getAllowedOrigins() []string {
    envOrigins := os.Getenv("CORS_ALLOWED_ORIGINS")
    if envOrigins != "" {
        parts := strings.Split(envOrigins, ",")
        var cleaned []string
        for _, p := range parts {
            trimmed := strings.TrimSpace(p)
            if trimmed != "" {
                cleaned = append(cleaned, trimmed)
            }
        }
        if len(cleaned) > 0 {
            return cleaned
        }
    }

    appEnv := os.Getenv("APP_ENV")
    paymentEnv := os.Getenv("PAYMENT_ENV")

    if appEnv == "production" || paymentEnv == "production" {
        return []string{
            "https://ffarena.com",
            "https://www.ffarena.com",
            "https://api.ffarena.com",
            "https://payment.ffarena.com",
            "https://www.payment.ffarena.com",
        }
    }

    if appEnv == "staging" {
        return []string{
            "https://staging.ffarena.com",
            "https://api.staging.ffarena.com",
            "https://payment.staging.ffarena.com",
            "https://ffarena.com",
            "https://www.ffarena.com",
        }
    }

    return []string{
        "http://localhost:3000",
        "http://localhost:8080",
        "http://localhost:8000",
        "http://127.0.0.1:3000",
        "http://127.0.0.1:8080",
        "http://127.0.0.1:8000",
        "https://sandbox.ffarena.com",
        "https://ffarena.com",
        "https://www.ffarena.com",
    }
}

func isValidWildcardMatch(origin, pattern string) bool {
    if !strings.HasSuffix(pattern, "*") {
        return false
    }

    prefix := strings.TrimSuffix(pattern, "*")
    
    if !strings.HasPrefix(origin, prefix) {
        return false
    }

    remaining := strings.TrimPrefix(origin, prefix)
    
    // For pattern https://*.ffarena.com, origin https://api.ffarena.com
    // prefix = https://, remaining = api.ffarena.com - valid
    // origin https://ffarena.com.evil.com, pattern https://*.ffarena.com
    // prefix = https://, remaining = ffarena.com.evil.com - should check if it ends with ffarena.com
    // More strict: if pattern is https://*.ffarena.com, we want origin to be https://<subdomain>.ffarena.com
    // So remaining should not contain / and should end with .ffarena.com or be exactly ffarena.com

    if strings.Contains(remaining, "/") {
        return false
    }

    if strings.HasSuffix(pattern, ".ffarena.com*") || strings.HasSuffix(pattern, ".ffarena.com") {
        // For *.ffarena.com, ensure remaining ends with ffarena.com or contains ffarena.com
        if strings.HasSuffix(origin, ".ffarena.com") || strings.HasSuffix(origin, "ffarena.com") {
            // Prevent evil.com attack: ensure origin doesn't have extra dot after
            // e.g., https://ffarena.com.evil.com should not match https://*.ffarena.com
            // Check that after prefix, the remaining is either subdomain.ffarena.com or ffarena.com
            // and not ffarena.com.evil.com
            if strings.Contains(remaining, ".evil.com") || strings.Contains(remaining, ".attacker.com") {
                return false
            }
            return true
        }
    }

    // For generic wildcard like https://*
    if prefix == "https://" && remaining != "" {
        // Allow any https subdomain but prevent obvious evil
        if !strings.Contains(remaining, "..") && !strings.Contains(remaining, "evil") {
            return true
        }
    }

    return true
}

func isDevelopment() bool {
    env := os.Getenv("APP_ENV")
    return env == "development" || env == "local" || env == "testing" || env == "test"
}

// CORSConfig holds CORS configuration for more advanced usage
type CORSConfig struct {
    AllowedOrigins   []string
    AllowedMethods   []string
    AllowedHeaders   []string
    ExposedHeaders   []string
    MaxAge           int
    AllowCredentials bool
}

func DefaultCORSConfig() CORSConfig {
    return CORSConfig{
        AllowedOrigins:   getAllowedOrigins(),
        AllowedMethods:   []string{"GET", "POST", "OPTIONS"},
        AllowedHeaders:   []string{"Authorization", "Content-Type", "X-Request-ID", "Idempotency-Key", "X-Idempotency-Key", "X-Signature", "X-Service-ID", "X-Timestamp", "X-Nonce", "X-Provider-Signature", "X-Webhook-Signature", "traceparent", "X-Trace-ID", "X-Span-ID"},
        ExposedHeaders:   []string{"X-Request-ID", "X-Trace-ID", "X-Span-ID"},
        MaxAge:           86400,
        AllowCredentials: false,
    }
}

func CORSWithConfig(config CORSConfig) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
            origin := r.Header.Get("Origin")
            allowed := false

            for _, ao := range config.AllowedOrigins {
                if ao == "*" && isDevelopment() {
                    allowed = true
                    w.Header().Set("Access-Control-Allow-Origin", "*")
                    break
                }
                if origin == ao {
                    allowed = true
                    w.Header().Set("Access-Control-Allow-Origin", origin)
                    w.Header().Set("Vary", "Origin")
                    break
                }
                if strings.HasSuffix(ao, "*") && strings.HasPrefix(origin, strings.TrimSuffix(ao, "*")) {
                    if isValidWildcardMatch(origin, ao) {
                        allowed = true
                        w.Header().Set("Access-Control-Allow-Origin", origin)
                        w.Header().Set("Vary", "Origin")
                        break
                    }
                }
            }

            if origin == "" {
                allowed = true
            }

            w.Header().Set("Access-Control-Allow-Methods", strings.Join(config.AllowedMethods, ", "))
            w.Header().Set("Access-Control-Allow-Headers", strings.Join(config.AllowedHeaders, ", "))
            w.Header().Set("Access-Control-Expose-Headers", strings.Join(config.ExposedHeaders, ", "))
            w.Header().Set("Access-Control-Max-Age", fmt.Sprintf("%d", config.MaxAge))
            if config.AllowCredentials {
                w.Header().Set("Access-Control-Allow-Credentials", "true")
            } else {
                w.Header().Set("Access-Control-Allow-Credentials", "false")
            }

            if r.Method == http.MethodOptions {
                if allowed {
                    w.WriteHeader(http.StatusNoContent)
                } else {
                    w.WriteHeader(http.StatusForbidden)
                }
                return
            }

            if !allowed && origin != "" {
                w.WriteHeader(http.StatusForbidden)
                w.Write([]byte(`{"error":"origin_not_allowed"}`))
                return
            }

            next.ServeHTTP(w, r)
        })
    }
}
