package models
import ("errors"; "time")
type Payment struct {
    ID string `json:"id"`
    UserID int64 `json:"user_id"`
    WalletID *int64 `json:"wallet_id,omitempty"`
    Provider string `json:"provider"`
    ExternalID string `json:"external_id"`
    ProviderReference *string `json:"provider_reference,omitempty"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    IdempotencyKey string `json:"idempotency_key"`
    IdempotencyFingerprint string `json:"idempotency_fingerprint,omitempty"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    AuthorizedAt *time.Time `json:"authorized_at,omitempty"`
    SucceededAt *time.Time `json:"succeeded_at,omitempty"`
    FailedAt *time.Time `json:"failed_at,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func (p *Payment) Validate() error {
    if p.AmountMinor<=0{return errors.New("amount must be positive")}
    if p.Provider==""{return errors.New("provider required")}
    if p.ExternalID==""{return errors.New("external_id required")}
    return nil
}
type Wallet struct {
    ID int64 `json:"id"`
    UserID int64 `json:"user_id"`
    Currency string `json:"currency"`
    BalanceMinor int64 `json:"balance_minor"`
    IsLocked bool `json:"is_locked"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
type LedgerEntry struct {
    ID int64 `json:"id"`
    WalletID int64 `json:"wallet_id"`
    UserID int64 `json:"user_id"`
    Direction string `json:"direction"`
    AmountMinor int64 `json:"amount_minor"`
    BalanceAfterMinor int64 `json:"balance_after_minor"`
    ReferenceType string `json:"reference_type"`
    ReferenceID string `json:"reference_id"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CreatedAt time.Time `json:"created_at"`
}
type Payout struct {
    ID string `json:"id"`
    UserID int64 `json:"user_id"`
    TournamentID *int64 `json:"tournament_id,omitempty"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    ExternalID string `json:"external_id"`
    Provider string `json:"provider"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
