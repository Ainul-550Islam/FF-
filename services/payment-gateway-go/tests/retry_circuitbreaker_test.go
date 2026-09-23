package tests

import (
	"errors"
	"testing"
	"time"
	"github.com/ffarena/payment-gateway-go/internal/circuitbreaker"
	"github.com/ffarena/payment-gateway-go/internal/observability"
	"github.com/ffarena/payment-gateway-go/internal/retry"
)

func TestRetryClassification(t *testing.T) {
    tests := []struct {
        err       error
        retryable bool
    }{
        {errors.New("timeout"), true},
        {errors.New("connection reset"), true},
        {errors.New("502 Bad Gateway"), true},
        {errors.New("503 Service Unavailable"), true},
        {errors.New("504 Gateway Timeout"), true},
        {errors.New("invalid credentials"), false},
        {errors.New("invalid amount"), false},
        {errors.New("invalid signature"), false},
        {errors.New("insufficient funds"), false},
        {errors.New("invalid request"), false},
        {errors.New("rejected payment"), false},
    }
    
    for _, tt := range tests {
        result := retry.IsRetryable(tt.err)
        if result != tt.retryable {
            t.Errorf("error %v expected retryable %v, got %v", tt.err, tt.retryable, result)
        }
    }
}

func TestCircuitBreaker(t *testing.T) {
    metrics := observability.NewInMemoryMetrics()
    cb := circuitbreaker.NewCircuitBreaker(3, 1*time.Second, "bkash", metrics)
    
    if cb.State() != circuitbreaker.StateClosed {
        t.Error("initial state should be closed")
    }
    
    // Fail 3 times to open circuit
    for i := 0; i < 3; i++ {
        cb.Call(func() error { return errors.New("failure") })
    }
    
    if cb.State() != circuitbreaker.StateOpen {
        t.Error("should be open after 3 failures")
    }
    
    // Should fail fast when open
    err := cb.Call(func() error { return nil })
    if err == nil {
        t.Error("should fail when circuit open")
    }
    
    // No payment should falsely report success because circuit is open
    err = cb.EnsureClosed()
    if err == nil {
        t.Error("EnsureClosed should fail when open")
    }
    
    // Wait for reset timeout
    time.Sleep(1100 * time.Millisecond)
    
    // Should go to half-open and allow call
    err = cb.Call(func() error { return nil })
    if err != nil {
        t.Errorf("should allow call in half-open: %v", err)
    }
}

func TestProviderCircuitBreakers(t *testing.T) {
    metrics := observability.NewInMemoryMetrics()
    pcb := circuitbreaker.NewProviderCircuitBreakers(metrics)
    
    bkashCB := pcb.Get("bkash")
    nagadCB := pcb.Get("nagad")
    rocketCB := pcb.Get("rocket")
    
    if bkashCB == nil || nagadCB == nil || rocketCB == nil {
        t.Error("should get circuit breakers for all providers")
    }
    
    // Track separately
    if pcb.Get("bkash") != bkashCB {
        t.Error("should return same breaker for same provider")
    }
}
