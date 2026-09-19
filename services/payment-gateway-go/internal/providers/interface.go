package providers

import "context"

type CreatePaymentRequest struct {
    UserID         int64                  `json:"user_id"`
    AmountMinor    int64                  `json:"amount_minor"`
    Currency       string                 `json:"currency"`
    Provider       string                 `json:"provider"`
    ExternalID     string                 `json:"external_id"`
    IdempotencyKey string                 `json:"idempotency_key"`
    Metadata       map[string]interface{} `json:"metadata,omitempty"`
    CallbackURL    string                 `json:"callback_url,omitempty"`
    CustomerEmail  string                 `json:"customer_email,omitempty"`
    CustomerPhone  string                 `json:"customer_phone,omitempty"`
}

type CreatePaymentResponse struct {
    ExternalID        string                 `json:"external_id"`
    ProviderReference string                 `json:"provider_reference"`
    Status            string                 `json:"status"`
    RedirectURL       *string                `json:"redirect_url,omitempty"`
    PaymentURL        *string                `json:"payment_url,omitempty"`
    Token             *string                `json:"token,omitempty"`
    Metadata          map[string]interface{} `json:"metadata,omitempty"`
}

type QueryPaymentRequest struct {
    ExternalID        string `json:"external_id"`
    ProviderReference string `json:"provider_reference"`
}

type QueryPaymentResponse struct {
    Status            string                 `json:"status"`
    ProviderReference string                 `json:"provider_reference"`
    AmountMinor       int64                  `json:"amount_minor"`
    Currency          string                 `json:"currency,omitempty"`
    Metadata          map[string]interface{} `json:"metadata,omitempty"`
}

type RefundRequest struct {
    PaymentID      string `json:"payment_id"`
    ExternalID     string `json:"external_id"`
    AmountMinor    int64  `json:"amount_minor"`
    Currency       string `json:"currency"`
    IdempotencyKey string `json:"idempotency_key"`
    Reason         string `json:"reason,omitempty"`
}

type RefundResponse struct {
    RefundID string `json:"refund_id"`
    Status   string `json:"status"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
}

type Provider interface {
    Key() string
    Label() string
    SupportsCurrency(currency string) bool
    SupportsRefund() bool
    CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error)
    QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error)
    VerifyWebhook(payload []byte, signature string) error
    HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error)
    Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error)
    Capabilities() []string
    Metadata() map[string]interface{}
    ValidateConfig() error
    HealthCheck(ctx context.Context) error
    IsEnabled() bool
    MapProviderStatus(providerStatus string) string
}

type ProviderStatusMapper interface {
    MapStatus(providerStatus string) string
}

const (
    InternalStatusCreated   = "created"
    InternalStatusPending   = "pending"
    InternalStatusProcessing = "processing"
    InternalStatusAuthorized = "authorized"
    InternalStatusSucceeded = "succeeded"
    InternalStatusFailed    = "failed"
    InternalStatusExpired   = "expired"
    InternalStatusCancelled = "cancelled"
    InternalStatusRefunding = "refunding"
    InternalStatusRefunded  = "refunded"
)
