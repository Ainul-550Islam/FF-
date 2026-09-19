package domain
import "time"
const (SettlementStatusPending="pending"; SettlementStatusProcessing="processing"; SettlementStatusCompleted="completed"; SettlementStatusFailed="failed")
type Settlement struct {
    ID string `json:"id"`
    TournamentID int64 `json:"tournament_id"`
    TotalAmountMinor int64 `json:"total_amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CompletedAt *time.Time `json:"completed_at,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
type ReconciliationRecord struct {
    ID string `json:"id"`
    PaymentID string `json:"payment_id"`
    Type string `json:"type"`
    ExpectedAmount int64 `json:"expected_amount"`
    ActualAmount int64 `json:"actual_amount"`
    Status string `json:"status"`
    Resolved bool `json:"resolved"`
    CreatedAt time.Time `json:"created_at"`
}
const (ReconciliationTypeAmountMismatch="amount_mismatch"; ReconciliationTypeStatusMismatch="status_mismatch"; ReconciliationTypeDuplicate="duplicate_external_reference"; ReconciliationTypeLedgerMismatch="ledger_mismatch")
