package providers

import (
    "context"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/google/uuid"
)

type ManualProvider struct {
    BaseProvider
    logger  *observability.Logger
    metrics observability.Metrics
}

func NewManualProvider(cfg ProviderConfig, logger *observability.Logger, metrics observability.Metrics) *ManualProvider {
    return &ManualProvider{BaseProvider: BaseProvider{Config: cfg}, logger: logger, metrics: metrics}
}
func (p *ManualProvider) Key() string { return "manual" }
func (p *ManualProvider) Label() string { return "Manual Payment" }
func (p *ManualProvider) SupportsCurrency(currency string) bool { return currency == "BDT" || currency == "USD" || currency == "EUR" }
func (p *ManualProvider) SupportsRefund() bool { return true }
func (p *ManualProvider) Capabilities() []string { return []string{"create", "query", "refund", "manual_review"} }
func (p *ManualProvider) Metadata() map[string]interface{} {
    return map[string]interface{}{
        "type": "manual",
        "currencies": []string{"BDT", "USD", "EUR"},
        "requires_review": true,
        "fallback": true,
    }
}
func (p *ManualProvider) IsEnabled() bool { return true } // Manual always enabled as fallback
func (p *ManualProvider) ValidateConfig() error { return nil }
func (p *ManualProvider) HealthCheck(ctx context.Context) error { return nil }
func (p *ManualProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {
    // Manual provider - requires manual review, no external API call
    // This is explicit fallback only when configured
    if p.logger != nil {
        p.logger.Info("manual payment created", map[string]interface{}{
            "provider": "manual",
            "external_id": req.ExternalID,
            "user_id": req.UserID,
            "amount": req.AmountMinor,
        })
    }
    if p.metrics != nil {
        p.metrics.Increment("manual_payment_created", map[string]string{"provider": "manual"})
    }
    resp := p.CreateBasePayment(req)
    resp.Status = "pending"
    resp.Metadata = map[string]interface{}{
        "type": "manual",
        "requires_review": true,
        "created_at": time.Now().UTC().Format(time.RFC3339),
        "review_note": "Manual payment requires admin review",
    }
    return &resp, nil
}
func (p *ManualProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {
    return &QueryPaymentResponse{Status: "pending", ProviderReference: req.ProviderReference, AmountMinor: 0}, nil
}
func (p *ManualProvider) VerifyWebhook(payload []byte, signature string) error {
    return p.VerifyHMAC(payload, signature, p.Config.Secret)
}
func (p *ManualProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {
    return &QueryPaymentResponse{Status: "pending", AmountMinor: 0}, nil
}
func (p *ManualProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {
    return &RefundResponse{RefundID: p.GenerateExternalID(), Status: "pending"}, nil
}
func (p *ManualProvider) MapProviderStatus(providerStatus string) string {
    switch providerStatus {
    case "succeeded", "completed", "success":
        return InternalStatusSucceeded
    case "failed", "failure":
        return InternalStatusFailed
    case "pending", "created", "processing":
        return providerStatus
    default:
        return InternalStatusPending
    }
}

var _ = uuid.New
