package circuitbreaker

import (
    "errors"
    "fmt"
    "sync"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
)

type State string

const (
    StateClosed   State = "closed"
    StateOpen     State = "open"
    StateHalfOpen State = "half_open"
)

type CircuitBreaker struct {
    mu           sync.RWMutex
    state        State
    failures     int
    maxFailures  int
    resetTimeout time.Duration
    lastFailure  time.Time
    successes    int
    provider     string
    metrics      observability.Metrics
    lastSuccess  time.Time
    lastFailureTime time.Time
}

type ProviderCircuitBreakers struct {
    mu       sync.RWMutex
    breakers map[string]*CircuitBreaker
}

func NewCircuitBreaker(maxFailures int, resetTimeout time.Duration, provider string, metrics observability.Metrics) *CircuitBreaker {
    return &CircuitBreaker{
        state:        StateClosed,
        maxFailures:  maxFailures,
        resetTimeout: resetTimeout,
        provider:     provider,
        metrics:      metrics,
    }
}

func NewProviderCircuitBreakers(metrics observability.Metrics) *ProviderCircuitBreakers {
    return &ProviderCircuitBreakers{
        breakers: make(map[string]*CircuitBreaker),
    }
}

func (pcb *ProviderCircuitBreakers) Get(provider string) *CircuitBreaker {
    pcb.mu.RLock()
    cb, ok := pcb.breakers[provider]
    pcb.mu.RUnlock()
    if ok {
        return cb
    }

    pcb.mu.Lock()
    defer pcb.mu.Unlock()
    // Double check
    if cb, ok := pcb.breakers[provider]; ok {
        return cb
    }

    cb = NewCircuitBreaker(5, 60*time.Second, provider, nil)
    pcb.breakers[provider] = cb
    return cb
}

func (pcb *ProviderCircuitBreakers) GetAll() map[string]*CircuitBreaker {
    pcb.mu.RLock()
    defer pcb.mu.RUnlock()
    result := make(map[string]*CircuitBreaker)
    for k, v := range pcb.breakers {
        result[k] = v
    }
    return result
}

func (cb *CircuitBreaker) State() State {
    cb.mu.RLock()
    defer cb.mu.RUnlock()
    return cb.state
}

func (cb *CircuitBreaker) Call(fn func() error) error {
    cb.mu.Lock()
    if cb.state == StateOpen {
        if time.Since(cb.lastFailure) < cb.resetTimeout {
            cb.mu.Unlock()
            if cb.metrics != nil {
                cb.metrics.Increment("provider_circuit_open_total", map[string]string{"provider": cb.provider})
            }
            return errors.New("circuit breaker open for provider " + cb.provider)
        }
        cb.state = StateHalfOpen
        cb.successes = 0
    }
    cb.mu.Unlock()

    err := fn()

    cb.mu.Lock()
    defer cb.mu.Unlock()

    if err != nil {
        cb.failures++
        cb.lastFailure = time.Now()
        cb.lastFailureTime = time.Now()
        if cb.failures >= cb.maxFailures {
            cb.state = StateOpen
            if cb.metrics != nil {
                cb.metrics.Increment("provider_circuit_open_total", map[string]string{"provider": cb.provider})
            }
        }
        if cb.metrics != nil {
            cb.metrics.Increment("provider_failures_total", map[string]string{"provider": cb.provider})
        }
        return err
    }

    if cb.state == StateHalfOpen {
        cb.successes++
        if cb.successes >= 2 {
            cb.state = StateClosed
            cb.failures = 0
            cb.successes = 0
            cb.lastSuccess = time.Now()
        }
    } else {
        cb.failures = 0
        cb.lastSuccess = time.Now()
    }

    return nil
}

func (cb *CircuitBreaker) Reset() {
    cb.mu.Lock()
    defer cb.mu.Unlock()
    cb.state = StateClosed
    cb.failures = 0
    cb.successes = 0
}

func (cb *CircuitBreaker) GetStats() map[string]interface{} {
    cb.mu.RLock()
    defer cb.mu.RUnlock()
    return map[string]interface{}{
        "provider":     cb.provider,
        "state":        cb.state,
        "failures":     cb.failures,
        "last_failure": cb.lastFailureTime,
        "last_success": cb.lastSuccess,
    }
}

func (cb *CircuitBreaker) IsOpen() bool {
    cb.mu.RLock()
    defer cb.mu.RUnlock()
    return cb.state == StateOpen
}

func (cb *CircuitBreaker) Health() map[string]interface{} {
    cb.mu.RLock()
    defer cb.mu.RUnlock()
    status := "ok"
    if cb.state == StateOpen {
        status = "down"
    } else if cb.state == StateHalfOpen {
        status = "degraded"
    }
    return map[string]interface{}{
        "provider": cb.provider,
        "status":   status,
        "state":    cb.state,
        "failures": cb.failures,
    }
}

func (pcb *ProviderCircuitBreakers) HealthCheck() map[string]interface{} {
    pcb.mu.RLock()
    defer pcb.mu.RUnlock()
    result := make(map[string]interface{})
    for provider, cb := range pcb.breakers {
        result[provider] = cb.Health()
    }
    return result
}

func (pcb *ProviderCircuitBreakers) ResetAll() {
    pcb.mu.Lock()
    defer pcb.mu.Unlock()
    for _, cb := range pcb.breakers {
        cb.Reset()
    }
}

func (pcb *ProviderCircuitBreakers) GetMetrics() map[string]interface{} {
    pcb.mu.RLock()
    defer pcb.mu.RUnlock()
    metrics := make(map[string]interface{})
    for provider, cb := range pcb.breakers {
        stats := cb.GetStats()
        metrics[provider] = stats
    }
    return metrics
}

// No payment should falsely report success because a circuit is open
func (cb *CircuitBreaker) EnsureClosed() error {
    cb.mu.RLock()
    defer cb.mu.RUnlock()
    if cb.state == StateOpen {
        return fmt.Errorf("circuit breaker open for provider %s - cannot process payment, circuit must be closed for success", cb.provider)
    }
    return nil
}
