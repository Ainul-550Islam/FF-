package domain
import ("errors"; "time")
const (
    StatusCreated="created"; StatusPending="pending"; StatusProcessing="processing"; StatusAuthorized="authorized"; StatusSucceeded="succeeded"; StatusFailed="failed"; StatusExpired="expired"; StatusCancelled="cancelled"; StatusRefunding="refunding"; StatusRefunded="refunded"
)
var validTransitions=map[string][]string{
    StatusCreated:{StatusPending,StatusCancelled,StatusExpired},
    StatusPending:{StatusProcessing,StatusFailed,StatusCancelled,StatusExpired},
    StatusProcessing:{StatusAuthorized,StatusSucceeded,StatusFailed,StatusExpired},
    StatusAuthorized:{StatusSucceeded,StatusFailed,StatusCancelled,StatusExpired},
    StatusSucceeded:{StatusRefunding,StatusRefunded},
    StatusFailed:{StatusPending},
    StatusRefunding:{StatusRefunded,StatusFailed},
    StatusRefunded:{}, StatusCancelled:{}, StatusExpired:{},
}
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
func NewPayment(userID int64, provider, externalID string, amountMinor int64, currency, idempotencyKey string) (*Payment, error) {
    if amountMinor<=0{return nil, errors.New("amount must be positive")}
    if len(currency)!=3{return nil, errors.New("currency must be 3 chars")}
    if provider==""{return nil, errors.New("provider required")}
    if externalID==""{return nil, errors.New("external_id required")}
    now:=time.Now().UTC()
    return &Payment{UserID:userID,Provider:provider,ExternalID:externalID,AmountMinor:amountMinor,Currency:currency,Status:StatusCreated,IdempotencyKey:idempotencyKey,CreatedAt:now,UpdatedAt:now}, nil
}
func (p *Payment) CanTransitionTo(newStatus string) bool {
    allowed,ok:=validTransitions[p.Status]
    if !ok{return false}
    for _,s:=range allowed{if s==newStatus{return true}}
    return false
}
func (p *Payment) Transition(newStatus string) error {
    if !p.CanTransitionTo(newStatus){return errors.New("invalid transition from "+p.Status+" to "+newStatus)}
    p.Status=newStatus
    now:=time.Now().UTC()
    p.UpdatedAt=now
    switch newStatus{
    case StatusAuthorized: p.AuthorizedAt=&now
    case StatusSucceeded: p.SucceededAt=&now
    case StatusFailed: p.FailedAt=&now
    }
    return nil
}
func (p *Payment) IsTerminal() bool {return p.Status==StatusRefunded||p.Status==StatusCancelled||p.Status==StatusExpired}
func (p *Payment) IsRefundable() bool {return p.Status==StatusSucceeded}
func (p *Payment) IsCancellable() bool {return p.Status==StatusCreated||p.Status==StatusPending}
