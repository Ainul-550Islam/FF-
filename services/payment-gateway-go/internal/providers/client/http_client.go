package client

import (
    "bytes"
    "context"
    "crypto/tls"
    "encoding/json"
    "fmt"
    "io"
    "net"
    "net/http"
    "sync"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/google/uuid"
)

type ProviderHTTPClient struct {
    client          *http.Client
    baseURL         string
    timeout         time.Duration
    logger          *observability.Logger
    metrics         observability.Metrics
    provider        string
    mu              sync.RWMutex
    requestIDHeader string
}

type RequestOptions struct {
    Method      string
    Path        string
    Body        interface{}
    Headers     map[string]string
    QueryParams map[string]string
    RequestID   string
    CorrelationID string
    IdempotencyKey string
}

type Response struct {
    StatusCode int
    Headers    http.Header
    Body       []byte
    LatencyMs  int64
    RequestID  string
}

type HTTPClientConfig struct {
    BaseURL         string
    Timeout         time.Duration
    Provider        string
    MaxIdleConns    int
    IdleConnTimeout time.Duration
    TLSVerify       bool
    Logger          *observability.Logger
    Metrics         observability.Metrics
}

func NewProviderHTTPClient(cfg HTTPClientConfig) *ProviderHTTPClient {
    if cfg.Timeout == 0 {
        cfg.Timeout = 15 * time.Second
    }
    if cfg.MaxIdleConns == 0 {
        cfg.MaxIdleConns = 20
    }
    if cfg.IdleConnTimeout == 0 {
        cfg.IdleConnTimeout = 90 * time.Second
    }
    transport := &http.Transport{
        Proxy: http.ProxyFromEnvironment,
        DialContext: (&net.Dialer{
            Timeout:   5 * time.Second,
            KeepAlive: 30 * time.Second,
        }).DialContext,
        MaxIdleConns:          cfg.MaxIdleConns,
        MaxIdleConnsPerHost:   cfg.MaxIdleConns,
        IdleConnTimeout:       cfg.IdleConnTimeout,
        TLSHandshakeTimeout:   5 * time.Second,
        ExpectContinueTimeout: 1 * time.Second,
        TLSClientConfig: &tls.Config{
            InsecureSkipVerify: !cfg.TLSVerify,
            MinVersion:         tls.VersionTLS12,
        },
    }
    client := &http.Client{
        Transport: transport,
        Timeout:   cfg.Timeout,
    }
    return &ProviderHTTPClient{
        client:          client,
        baseURL:         cfg.BaseURL,
        timeout:         cfg.Timeout,
        logger:          cfg.Logger,
        metrics:         cfg.Metrics,
        provider:        cfg.Provider,
        requestIDHeader: "X-Request-ID",
    }
}

func (c *ProviderHTTPClient) Do(ctx context.Context, opts RequestOptions) (*Response, error) {
    start := time.Now()
    requestID := opts.RequestID
    if requestID == "" {
        requestID = uuid.New().String()
    }
    correlationID := opts.CorrelationID
    if correlationID == "" {
        correlationID = uuid.New().String()
    }

    url := c.baseURL + opts.Path
    if len(opts.QueryParams) > 0 {
        q := "?"
        first := true
        for k, v := range opts.QueryParams {
            if !first {
                q += "&"
            }
            q += k + "=" + v
            first = false
        }
        url += q
    }

    var bodyReader io.Reader
    var bodyBytes []byte
    if opts.Body != nil {
        var err error
        switch b := opts.Body.(type) {
        case []byte:
            bodyBytes = b
        case string:
            bodyBytes = []byte(b)
        default:
            bodyBytes, err = json.Marshal(opts.Body)
            if err != nil {
                return nil, fmt.Errorf("marshal body failed: %w", err)
            }
        }
        bodyReader = bytes.NewReader(bodyBytes)
    }

    req, err := http.NewRequestWithContext(ctx, opts.Method, url, bodyReader)
    if err != nil {
        return nil, fmt.Errorf("create request failed: %w", err)
    }

    req.Header.Set("Content-Type", "application/json")
    req.Header.Set("Accept", "application/json")
    req.Header.Set(c.requestIDHeader, requestID)
    req.Header.Set("X-Correlation-ID", correlationID)
    if opts.IdempotencyKey != "" {
        req.Header.Set("X-Idempotency-Key", opts.IdempotencyKey)
        req.Header.Set("Idempotency-Key", opts.IdempotencyKey)
    }
    for k, v := range opts.Headers {
        req.Header.Set(k, v)
    }

    // Structured logging with redaction
    redactedHeaders := c.redactHeaders(req.Header)
    if c.logger != nil {
        c.logger.Info("provider request", map[string]interface{}{
            "provider":       c.provider,
            "method":         opts.Method,
            "path":           opts.Path,
            "request_id":     requestID,
            "correlation_id": correlationID,
            "headers":        redactedHeaders,
        })
    }

    resp, err := c.client.Do(req)
    latencyMs := time.Since(start).Milliseconds()
    
    if c.metrics != nil {
        c.metrics.Timing("provider_latency_ms", latencyMs, map[string]string{"provider": c.provider})
        c.metrics.Increment("provider_requests_total", map[string]string{"provider": c.provider, "method": opts.Method})
    }

    if err != nil {
        if c.metrics != nil {
            c.metrics.Increment("provider_failure_total", map[string]string{"provider": c.provider, "type": "network"})
            if ctx.Err() == context.DeadlineExceeded {
                c.metrics.Increment("provider_timeout_total", map[string]string{"provider": c.provider})
            }
        }
        if c.logger != nil {
            c.logger.Error("provider request failed", map[string]interface{}{
                "provider":       c.provider,
                "method":         opts.Method,
                "path":           opts.Path,
                "request_id":     requestID,
                "correlation_id": correlationID,
                "latency_ms":     latencyMs,
                "error":          err.Error(),
            })
        }
        return nil, fmt.Errorf("provider request failed [%s]: %w", c.provider, err)
    }
    defer resp.Body.Close()

    // Body size limit 2MB
    limitedReader := io.LimitReader(resp.Body, 2*1024*1024)
    respBody, err := io.ReadAll(limitedReader)
    if err != nil {
        return nil, fmt.Errorf("read response body failed: %w", err)
    }

    // Validate JSON if content-type is json
    contentType := resp.Header.Get("Content-Type")
    if len(respBody) > 0 && (contentType == "" || contains(contentType, "json")) {
        if !json.Valid(respBody) && resp.StatusCode < 400 {
            if c.logger != nil {
                c.logger.Error("provider malformed json response", map[string]interface{}{
                    "provider":   c.provider,
                    "status":     resp.StatusCode,
                    "request_id": requestID,
                })
            }
            return nil, fmt.Errorf("provider %s returned malformed json", c.provider)
        }
    }

    response := &Response{
        StatusCode: resp.StatusCode,
        Headers:    resp.Header,
        Body:       respBody,
        LatencyMs:  latencyMs,
        RequestID:  requestID,
    }

    if c.logger != nil {
        logFields := map[string]interface{}{
            "provider":       c.provider,
            "method":         opts.Method,
            "path":           opts.Path,
            "status":         resp.StatusCode,
            "request_id":     requestID,
            "correlation_id": correlationID,
            "latency_ms":     latencyMs,
        }
        if resp.StatusCode >= 400 {
            c.logger.Error("provider response error", logFields)
            if c.metrics != nil {
                c.metrics.Increment("provider_failure_total", map[string]string{"provider": c.provider, "status": fmt.Sprintf("%d", resp.StatusCode)})
            }
        } else {
            c.logger.Info("provider response success", logFields)
            if c.metrics != nil {
                c.metrics.Increment("provider_success_total", map[string]string{"provider": c.provider})
            }
        }
    }

    return response, nil
}

func (c *ProviderHTTPClient) redactHeaders(headers http.Header) map[string]string {
    redacted := make(map[string]string)
    sensitive := []string{"authorization", "x-api-key", "api-key", "secret", "password", "token", "signature", "private-key"}
    for k, v := range headers {
        lower := bytes.ToLower([]byte(k))
        isSensitive := false
        for _, s := range sensitive {
            if bytes.Contains(lower, []byte(s)) {
                isSensitive = true
                break
            }
        }
        if isSensitive {
            redacted[k] = "***REDACTED***"
        } else {
            if len(v) > 0 {
                redacted[k] = v[0]
            }
        }
    }
    return redacted
}

func contains(s, substr string) bool {
    return bytes.Contains([]byte(s), []byte(substr))
}

func (c *ProviderHTTPClient) Close() {
    if transport, ok := c.client.Transport.(*http.Transport); ok {
        transport.CloseIdleConnections()
    }
}
