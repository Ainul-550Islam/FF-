package domain
import "time"
const (RefundStatusPending="pending"; RefundStatusProcessing="processing"; RefundStatusSucceeded="succeeded"; RefundStatusFailed="failed")
type Refund struct {
    ID string `json:"id"`
    PaymentID string `json:"payment_id"`
    ExternalID string `json:"external_id"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func NewRefund(paymentID, externalID string, amountMinor int64, currency, idempotencyKey string) *Refund {
    now:=time.Now().UTC()
    return &Refund{PaymentID:paymentID,ExternalID:externalID,AmountMinor:amountMinor,Currency:currency,Status:RefundStatusPending,IdempotencyKey:idempotencyKey,CreatedAt:now,UpdatedAt:now}
}
