package health

import (
    "context"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/circuitbreaker"
    "github.com/ffarena/payment-gateway-go/internal/config"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/storage"
)

type Service struct {
    config         *config.Config
    store          storage.Store
    metrics        observability.Metrics
    providerMgr    map[string]providers.Provider
    circuitBreakers *circuitbreaker.ProviderCircuitBreakers
}

func NewService(cfg *config.Config, store storage.Store, metrics observability.Metrics, providerMgr map[string]providers.Provider, cb *circuitbreaker.ProviderCircuitBreakers) *Service {
    return &Service{config: cfg, store: store, metrics: metrics, providerMgr: providerMgr, circuitBreakers: cb}
}

type Health struct {
    Status    string            `json:"status"`
    Service   string            `json:"service"`
    Version   string            `json:"version"`
    Env       string            `json:"env"`
    Timestamp string            `json:"timestamp"`
    Checks    map[string]string `json:"checks,omitempty"`
    Providers map[string]interface{} `json:"providers,omitempty"`
}

type ProviderHealth struct {
    Provider    string `json:"provider"`
    Status      string `json:"status"`
    LatencyMs   int64  `json:"latency_ms,omitempty"`
    LastSuccess *time.Time `json:"last_success,omitempty"`
    LastFailure *time.Time `json:"last_failure,omitempty"`
    CircuitState string `json:"circuit_state"`
    Message     string `json:"message,omitempty"`
}

func (s *Service) Check(ctx context.Context) *Health {
    checks := make(map[string]string)
    if err := s.store.HealthCheck(ctx); err != nil {
        checks["database"] = "down: " + err.Error()
    } else {
        checks["database"] = "ok"
    }

    providersHealth := make(map[string]interface{})
    overallStatus := "ok"

    for key, provider := range s.providerMgr {
        start := time.Now()
        err := provider.HealthCheck(ctx)
        latency := time.Since(start).Milliseconds()

        circuitState := "closed"
        if s.circuitBreakers != nil {
            if cb := s.circuitBreakers.Get(key); cb != nil {
                circuitState = string(cb.State())
            }
        }

        ph := ProviderHealth{
            Provider:     key,
            LatencyMs:    latency,
            CircuitState: circuitState,
        }

        if err != nil {
            ph.Status = "down"
            ph.Message = err.Error()
            checks["provider_"+key] = "down: " + err.Error()
            if overallStatus != "down" {
                overallStatus = "degraded"
            }
        } else {
            ph.Status = "ok"
            checks["provider_"+key] = "ok"
            if circuitState == "open" {
                ph.Status = "degraded"
                if overallStatus == "ok" {
                    overallStatus = "degraded"
                }
            }
        }

        // Never reveal credentials, tokens, private keys
        providersHealth[key] = map[string]interface{}{
            "provider":      ph.Provider,
            "status":        ph.Status,
            "latency_ms":    ph.LatencyMs,
            "circuit_state": ph.CircuitState,
            // Do not include credentials, tokens, private keys
        }
    }

    for _, v := range checks {
        if v != "ok" && !isProviderCheck(v) {
            if overallStatus == "ok" {
                overallStatus = "degraded"
            }
        }
        if containsDown(v) {
            overallStatus = "down"
            break
        }
    }

    return &Health{
        Status:    overallStatus,
        Service:   s.config.ServiceID,
        Version:   s.config.Version,
        Env:       s.config.Env,
        Timestamp: time.Now().UTC().Format(time.RFC3339),
        Checks:    checks,
        Providers: providersHealth,
    }
}

func (s *Service) Live() *Health {
    return &Health{
        Status:    "ok",
        Service:   s.config.ServiceID,
        Version:   s.config.Version,
        Env:       s.config.Env,
        Timestamp: time.Now().UTC().Format(time.RFC3339),
    }
}

func (s *Service) Ready(ctx context.Context) (*Health, int) {
    h := s.Check(ctx)
    code := 200
    if h.Status == "down" {
        code = 503
    } else if h.Status == "degraded" {
        code = 200 // Ready but degraded is still 200, down is 503
        // For readiness, degraded should be 200, only down is 503
        // But if database down, then 503
        if h.Checks["database"] != "ok" {
            code = 503
        }
    }
    return h, code
}

func (s *Service) ProviderHealth(ctx context.Context, providerKey string) (*ProviderHealth, error) {
    provider, ok := s.providerMgr[providerKey]
    if !ok {
        return nil, ErrProviderNotFound
    }

    start := time.Now()
    err := provider.HealthCheck(ctx)
    latency := time.Since(start).Milliseconds()

    circuitState := "closed"
    if s.circuitBreakers != nil {
        if cb := s.circuitBreakers.Get(providerKey); cb != nil {
            circuitState = string(cb.State())
        }
    }

    ph := &ProviderHealth{
        Provider:     providerKey,
        LatencyMs:    latency,
        CircuitState: circuitState,
    }

    if err != nil {
        ph.Status = "down"
        ph.Message = err.Error()
    } else {
        ph.Status = "ok"
    }

    return ph, nil
}

var ErrProviderNotFound = &ProviderNotFoundError{"provider not found"}

type ProviderNotFoundError struct {
    msg string
}

func (e *ProviderNotFoundError) Error() string { return e.msg }

func isProviderCheck(check string) bool {
    return len(check) > 9 && check[:9] == "provider_"
}

func containsDown(s string) bool {
    return len(s) >= 4 && (s[:4] == "down" || contains(s, "down"))
}

func contains(s, substr string) bool {
    return len(s) >= len(substr) && (s == substr || len(s) > len(substr) && (s[0:len(substr)] == substr || contains(s[1:], substr)))
}
