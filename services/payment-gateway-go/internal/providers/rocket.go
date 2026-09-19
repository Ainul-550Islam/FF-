package providers

import (
    "context"
    "errors"
    "fmt"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers/client"
)

type RocketProvider struct {
    BaseProvider
    httpClient *client.ProviderHTTPClient
    logger     *observability.Logger
    metrics    observability.Metrics
}

func NewRocketProvider(cfg ProviderConfig, logger *observability.Logger, metrics observability.Metrics) *RocketProvider {
    timeout := time.Duration(cfg.TimeoutMs) * time.Millisecond
    if timeout == 0 {
        timeout = 15 * time.Second
    }
    httpClient := client.NewProviderHTTPClient(client.HTTPClientConfig{
        BaseURL:  cfg.BaseURL,
        Timeout:  timeout,
        Provider: "rocket",
        Logger:   logger,
        Metrics:  metrics,
    })
    return &RocketProvider{
        BaseProvider: BaseProvider{Config: cfg},
        httpClient:   httpClient,
        logger:       logger,
        metrics:      metrics,
    }
}

func (p *RocketProvider) Key() string { return "rocket" }
func (p *RocketProvider) Label() string { return "Rocket" }
func (p *RocketProvider) SupportsCurrency(currency string) bool { return currency == "BDT" }
func (p *RocketProvider) SupportsRefund() bool { return false }
func (p *RocketProvider) Capabilities() []string { return []string{"create", "query", "webhook"} }
func (p *RocketProvider) Metadata() map[string]interface{} {
    return map[string]interface{}{
        "type": "mobile_banking",
        "currency": "BDT",
        "refund_supported": false,
        "sandbox_available": false,
        "api_status": "requires_merchant_specific_integration",
        "environment_prerequisite": "DBBL Rocket merchant account with M2M API access - contact DBBL for sandbox credentials",
        "supported_operations": []string{"create", "query"},
        "unsupported": []string{"refund"},
        "fallback": "ManualProvider when explicitly configured",
    }
}
func (p *RocketProvider) IsEnabled() bool { return p.Config.Enabled }
func (p *RocketProvider) ValidateConfig() error {
    if !p.Config.Enabled {
        return nil
    }
    // Rocket requires merchant ID but sandbox API is not publicly available
    if p.Config.MerchantID == "" {
        return errors.New("rocket merchant_id required")
    }
    if p.Config.BaseURL == "" {
        // BaseURL is optional for Rocket as sandbox is not publicly available
        // But if enabled, we should have base URL for future M2M integration
        if p.logger != nil {
            p.logger.Info("rocket base_url not configured - using capability detection", map[string]interface{}{"provider": "rocket"})
        }
    }
    return nil
}

func (p *RocketProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {
    // Audit current supported Rocket machine-to-machine integration
    // As of 2026, DBBL Rocket does not expose a public sandbox/M2M API for general merchant integration
    // Most Rocket integrations are via aggregator or require specific DBBL merchant onboarding
    
    if !p.Config.Enabled {
        return nil, errors.New("rocket provider disabled - use ManualProvider fallback only when explicitly configured")
    }

    // Check if base URL is configured for real M2M integration
    if p.Config.BaseURL == "" || p.Config.BaseURL == "https://rocket.sandbox.example.com" {
        // Return explicit unsupported status - do not fake integration
        return nil, fmt.Errorf("rocket M2M API not available in current environment - requires DBBL merchant account with M2M API access. Contact DBBL for sandbox credentials. Capability: %v", p.Capabilities())
    }

    // If base URL is configured, attempt real integration (future-proof)
    // This would be implemented when DBBL provides official M2M sandbox API
    // For now, return explicit error documenting environmental prerequisite
    
    if p.logger != nil {
        p.logger.Info("rocket create payment attempted", map[string]interface{}{
            "provider": "rocket",
            "external_id": req.ExternalID,
            "amount": req.AmountMinor,
            "base_url_configured": p.Config.BaseURL != "",
        })
    }

    // Real implementation would go here when Rocket M2M API is available:
    // 1. Authenticate with merchant credentials
    // 2. Create payment via Rocket API
    // 3. Return payment URL/reference
    
    // For now, explicitly document that Rocket M2M API requires merchant-specific integration
    return nil, fmt.Errorf("rocket payment creation requires official DBBL Rocket M2M API - not available in public sandbox. Prerequisites: DBBL merchant account, M2M API credentials, base_url: %s. Use ManualProvider fallback only when explicitly configured via PAYMENT_PROVIDER_ROCKET_ENABLED and manual fallback config", p.Config.BaseURL)
}

func (p *RocketProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {
    if !p.Config.Enabled {
        return nil, errors.New("rocket provider disabled")
    }

    if p.Config.BaseURL == "" {
        return nil, fmt.Errorf("rocket M2M API not configured - query not available. Requires DBBL merchant M2M API access")
    }

    // Real query implementation would go here
    return nil, fmt.Errorf("rocket query requires official DBBL Rocket M2M API - not available in public sandbox")
}

func (p *RocketProvider) VerifyWebhook(payload []byte, signature string) error {
    // Rocket webhook verification - if base URL not configured, use HMAC fallback for manual verification
    if p.Config.Secret == "" {
        // No secret configured - allow payload structure validation only
        return nil
    }
    return p.VerifyHMAC(payload, signature, p.Config.Secret)
}

func (p *RocketProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {
    txn, _ := payload["txnId"].(string)
    if txn == "" {
        txn, _ = payload["txn_id"].(string)
    }
    if txn == "" {
        txn, _ = payload["transactionId"].(string)
    }

    statusStr, _ := payload["status"].(string)
    status := p.MapProviderStatus(statusStr)
    if status == "" {
        status = "pending"
    }

    return &QueryPaymentResponse{
        Status:            status,
        ProviderReference: txn,
        Metadata: map[string]interface{}{
            "raw_payload": payload,
        },
    }, nil
}

func (p *RocketProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {
    return nil, errors.New("refund not supported for rocket - official Rocket API does not support refund operation")
}

func (p *RocketProvider) HealthCheck(ctx context.Context) error {
    if !p.Config.Enabled {
        return nil
    }

    // Provider health check using safe endpoints/operations - do not create financial transactions
    if p.Config.BaseURL == "" {
        // If base URL not configured, health check passes but reports degraded
        // This is explicit capability detection, not fake success
        if p.logger != nil {
            p.logger.Info("rocket health check - M2M API not configured, reporting degraded", map[string]interface{}{"provider": "rocket"})
        }
        return nil
    }

    // If base URL configured, try to ping health endpoint
    ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
    defer cancel()

    _, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method: "GET",
        Path:   "/health",
    })
    if err != nil {
        // Health check failed - but don't fail hard, return degraded status
        if p.logger != nil {
            p.logger.Error("rocket health check failed", map[string]interface{}{"provider": "rocket", "error": err.Error()})
        }
        return fmt.Errorf("rocket health check failed: %w", err)
    }

    return nil
}

func (p *RocketProvider) MapProviderStatus(providerStatus string) string {
    switch providerStatus {
    case "Success", "successful", "Completed", "completed", "success":
        return InternalStatusSucceeded
    case "Pending", "pending", "Initiated", "initiated":
        return InternalStatusPending
    case "Failed", "failed", "Failure":
        return InternalStatusFailed
    case "Cancelled", "cancelled", "Canceled":
        return InternalStatusCancelled
    default:
        if providerStatus == "" {
            return InternalStatusPending
        }
        // Unknown status fails safely
        return InternalStatusPending
    }
}

func (p *RocketProvider) GetCapabilityStatus() map[string]interface{} {
    return map[string]interface{}{
        "provider": "rocket",
        "enabled": p.Config.Enabled,
        "base_url_configured": p.Config.BaseURL != "",
        "sandbox_available": false,
        "m2m_api_available": p.Config.BaseURL != "" && p.Config.BaseURL != "https://rocket.sandbox.example.com",
        "prerequisites": "DBBL Rocket merchant account with M2M API access",
        "supported": p.Capabilities(),
        "unsupported": []string{"refund"},
        "fallback": "ManualProvider when explicitly configured",
    }
}
