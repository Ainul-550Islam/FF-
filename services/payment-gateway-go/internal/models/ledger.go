package models
import "time"
type LedgerIntegrityReport struct {
    WalletID int64 `json:"wallet_id"`
    Calculated int64 `json:"calculated"`
    Stored int64 `json:"stored"`
    IsValid bool `json:"is_valid"`
    CheckedAt time.Time `json:"checked_at"`
    EntryCount int `json:"entry_count"`
    FirstMismatch *int `json:"first_mismatch,omitempty"`
}
