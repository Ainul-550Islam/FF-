package retry

import (
    "context"
    "errors"
    "fmt"
    "math/rand"
    "net"
    "strings"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
)

type RetryConfig struct {
    MaxRetries    int
    BaseDelay     time.Duration
    MaxDelay      time.Duration
    Jitter        bool
    Provider      string
    Metrics       observability.Metrics
}

type RetryableError struct {
    Err         error
    Retryable   bool
    StatusCode  int
    Provider    string
}

func (e *RetryableError) Error() string {
    return fmt.Sprintf("retryable=%v status=%d provider=%s: %v", e.Retryable, e.StatusCode, e.Provider, e.Err)
}

func RetryWithBackoff(ctx context.Context, fn func() error, cfg RetryConfig) error {
    var lastErr error
    for i := 0; i < cfg.MaxRetries; i++ {
        select {
        case <-ctx.Done():
            return ctx.Err()
        default:
        }

        err := fn()
        if err == nil {
            return nil
        }

        lastErr = err

        // Classify error for provider-specific retry
        retryable := IsRetryable(err)

        if !retryable {
            if cfg.Metrics != nil {
                cfg.Metrics.Increment("provider_retry_skipped", map[string]string{"provider": cfg.Provider, "reason": "non_retryable"})
            }
            return err
        }

        if i < cfg.MaxRetries-1 {
            delay := ExponentialBackoffWithJitter(i, cfg.BaseDelay, cfg.MaxDelay, cfg.Jitter)
            
            if cfg.Metrics != nil {
                cfg.Metrics.Increment("provider_retry_total", map[string]string{"provider": cfg.Provider})
            }

            select {
            case <-ctx.Done():
                return ctx.Err()
            case <-time.After(delay):
            }
        }
    }
    return lastErr
}

func ExponentialBackoff(attempt int, baseDelay time.Duration) time.Duration {
    delay := baseDelay * time.Duration(1<<uint(attempt))
    if delay > 30*time.Second {
        delay = 30 * time.Second
    }
    return delay
}

func ExponentialBackoffWithJitter(attempt int, baseDelay, maxDelay time.Duration, jitter bool) time.Duration {
    delay := baseDelay * time.Duration(1<<uint(attempt))
    if delay > maxDelay {
        delay = maxDelay
    }
    if jitter {
        // Add jitter: random between 0.8*delay and 1.2*delay
        jitterFactor := 0.8 + rand.Float64()*0.4
        delay = time.Duration(float64(delay) * jitterFactor)
    }
    return delay
}

func IsRetryable(err error) bool {
    if err == nil {
        return false
    }

    errStr := strings.ToLower(err.Error())

    // Retry only:
    // - network timeout
    // - connection reset
    // - 502, 503, 504
    // - documented transient provider errors

    // Network timeout
    if strings.Contains(errStr, "timeout") || strings.Contains(errStr, "deadline exceeded") {
        return true
    }

    // Connection reset
    if strings.Contains(errStr, "connection reset") || strings.Contains(errStr, "connection refused") || strings.Contains(errStr, "broken pipe") {
        return true
    }

    // Check for net errors
    var netErr net.Error
    if errors.As(err, &netErr) {
        if netErr.Timeout() {
            return true
        }
    }

    // 502, 503, 504
    if strings.Contains(errStr, "502") || strings.Contains(errStr, "503") || strings.Contains(errStr, "504") ||
       strings.Contains(errStr, "bad gateway") || strings.Contains(errStr, "service unavailable") || strings.Contains(errStr, "gateway timeout") {
        return true
    }

    // Transient provider errors
    if strings.Contains(errStr, "transient") || strings.Contains(errStr, "temporary") || strings.Contains(errStr, "try again") {
        return true
    }

    // Do NOT retry:
    // - invalid credentials
    // - invalid amount
    // - invalid signature
    // - insufficient funds
    // - invalid request
    // - rejected payment
    // - duplicate non-idempotent request

    nonRetryable := []string{
        "invalid credentials", "invalid amount", "invalid signature", "insufficient funds",
        "invalid request", "rejected", "duplicate", "unauthorized", "forbidden", "not found",
        "invalid_api_key", "invalid_merchant", "authentication failed",
    }

    for _, nr := range nonRetryable {
        if strings.Contains(errStr, nr) {
            return false
        }
    }

    // 4xx generally not retryable except 429, 408
    if strings.Contains(errStr, "400") || strings.Contains(errStr, "401") || strings.Contains(errStr, "403") || strings.Contains(errStr, "404") {
        // Check if it's 429 or 408 which are retryable
        if strings.Contains(errStr, "429") || strings.Contains(errStr, "408") {
            return true
        }
        return false
    }

    return false
}

func ClassifyProviderError(err error, statusCode int, provider string) *RetryableError {
    retryable := IsRetryable(err)
    
    // Provider-specific retry classification
    switch provider {
    case "bkash":
        // bKash specific transient errors
        if statusCode == 503 || statusCode == 504 || statusCode == 502 {
            retryable = true
        }
        // bKash invalid token should not retry without refresh
        if strings.Contains(strings.ToLower(err.Error()), "invalid token") {
            retryable = false
        }
    case "nagad":
        if statusCode == 503 || statusCode == 504 {
            retryable = true
        }
        // Nagad invalid signature should not retry
        if strings.Contains(strings.ToLower(err.Error()), "invalid signature") {
            retryable = false
        }
    case "rocket":
        if statusCode == 503 || statusCode == 504 {
            retryable = true
        }
    }

    return &RetryableError{
        Err:        err,
        Retryable:  retryable,
        StatusCode: statusCode,
        Provider:   provider,
    }
}
