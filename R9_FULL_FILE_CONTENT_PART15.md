# R9 Full File Content Part 15 - Files 211-225

Total files in this part: 15

## File: ./services/payment-gateway-go/internal/domain/idempotency.go

```
package domain
import ("crypto/sha256"; "encoding/json"; "fmt"; "time"; "github.com/google/uuid")
type IdempotencyRecord struct {
    Key string `json:"key"`
    Fingerprint string `json:"fingerprint"`
    Operation string `json:"operation"`
    UserID *int64 `json:"user_id,omitempty"`
    RequestBody map[string]interface{} `json:"request_body,omitempty"`
    ResponseBody map[string]interface{} `json:"response_body,omitempty"`
    StatusCode *int `json:"status_code,omitempty"`
    ExpiresAt time.Time `json:"expires_at"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func GenerateFingerprint(data interface{}) (string, error) {
    b,err:=json.Marshal(data)
    if err!=nil{return "", err}
    h:=sha256.Sum256(b)
    return fmt.Sprintf("%x",h), nil
}
func GenerateKey() string {return uuid.New().String()}
func NewIdempotencyRecord(key, operation string, requestBody map[string]interface{}) (*IdempotencyRecord, error) {
    fp,err:=GenerateFingerprint(requestBody)
    if err!=nil{return nil, err}
    now:=time.Now().UTC()
    return &IdempotencyRecord{Key:key,Fingerprint:fp,Operation:operation,RequestBody:requestBody,ExpiresAt:now.Add(24*time.Hour),CreatedAt:now,UpdatedAt:now}, nil
}
func (r *IdempotencyRecord) IsExpired() bool {return time.Now().UTC().After(r.ExpiresAt)}
```

## File: ./services/payment-gateway-go/internal/domain/payment.go

```
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
```

## File: ./services/payment-gateway-go/internal/domain/refund.go

```
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
```

## File: ./services/payment-gateway-go/internal/domain/settlement.go

```
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
```

## File: ./services/payment-gateway-go/internal/domain/webhook.go

```
package domain
import "time"
const (WebhookStateReceived="received"; WebhookStateValidated="validated"; WebhookStateProcessing="processing"; WebhookStateProcessed="processed"; WebhookStateFailed="failed"; WebhookStateDuplicate="duplicate")
type WebhookEvent struct {
    ID string `json:"id"`
    Provider string `json:"provider"`
    EventType string `json:"event_type"`
    EventID string `json:"event_id"`
    Payload map[string]interface{} `json:"payload"`
    Signature string `json:"signature,omitempty"`
    State string `json:"state"`
    Attempts int `json:"attempts"`
    LastError *string `json:"last_error,omitempty"`
    ProcessedAt *time.Time `json:"processed_at,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func NewWebhookEvent(provider, eventType, eventID string, payload map[string]interface{}) *WebhookEvent {
    now:=time.Now().UTC()
    return &WebhookEvent{Provider:provider,EventType:eventType,EventID:eventID,Payload:payload,State:WebhookStateReceived,Attempts:0,CreatedAt:now,UpdatedAt:now}
}
func (w *WebhookEvent) CanRetry() bool {return w.Attempts<3&&w.State!=WebhookStateProcessed}
func (w *WebhookEvent) MarkProcessed(){now:=time.Now().UTC(); w.State=WebhookStateProcessed; w.ProcessedAt=&now; w.UpdatedAt=now}
func (w *WebhookEvent) MarkFailed(errMsg string){w.State=WebhookStateFailed; w.LastError=&errMsg; w.Attempts++; w.UpdatedAt=time.Now().UTC()}
```

## File: ./services/payment-gateway-go/internal/events/events.go

```
package events
import "time"
const (
    EventPaymentCreated="payment.created.v1"
    EventPaymentSucceeded="payment.succeeded.v1"
    EventPaymentFailed="payment.failed.v1"
    EventPaymentRefunded="payment.refunded.v1"
    EventPayoutCreated="payout.created.v1"
    EventPayoutCompleted="payout.completed.v1"
    EventWebhookReceived="webhook.received.v1"
)
type Event struct{ID string `json:"id"`; Type string `json:"type"`; Version string `json:"version"`; Source string `json:"source"`; Timestamp time.Time `json:"timestamp"`; Data map[string]interface{} `json:"data"`; Metadata map[string]interface{} `json:"metadata,omitempty"`}
func NewEvent(eventType string, data map[string]interface{}) *Event {
    return &Event{Type:eventType,Version:"v1",Source:"payment-gateway-go",Timestamp:time.Now().UTC(),Data:data,Metadata:map[string]interface{}{"service":"payment-gateway-go"}}
}
type Publisher interface{Publish(event *Event) error}
type InMemoryPublisher struct{events []*Event}
func NewInMemoryPublisher() *InMemoryPublisher {return &InMemoryPublisher{events: make([]*Event,0)}}
func (p *InMemoryPublisher) Publish(event *Event) error{p.events=append(p.events,event); return nil}
func (p *InMemoryPublisher) Events() []*Event{return p.events}
```

## File: ./services/payment-gateway-go/internal/handlers/health.go

```
package handlers
import ("encoding/json"; "net/http"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability")
type HealthHandler struct{checker *observability.HealthChecker; metrics observability.Metrics}
func NewHealthHandler(checker *observability.HealthChecker, metrics observability.Metrics) *HealthHandler {return &HealthHandler{checker:checker,metrics:metrics}}
func (h *HealthHandler) Live(w http.ResponseWriter, r *http.Request){h.checker.LiveHandler(w,r)}
func (h *HealthHandler) Ready(w http.ResponseWriter, r *http.Request){h.checker.ReadyHandler(w,r)}
func (h *HealthHandler) Metrics(w http.ResponseWriter, r *http.Request){
    w.Header().Set("Content-Type","application/json")
    counters:=h.metrics.AllCounters()
    json.NewEncoder(w).Encode(map[string]interface{}{"counters":counters,"timestamp":time.Now().UTC().Format(time.RFC3339),"service":"payment-gateway-go"})
}
func (h *HealthHandler) Health(w http.ResponseWriter, r *http.Request){
    w.Header().Set("Content-Type","application/json"); w.WriteHeader(200)
    json.NewEncoder(w).Encode(map[string]interface{}{"status":"ok","service":"payment-gateway-go","timestamp":time.Now().UTC().Format(time.RFC3339)})
}
```

## File: ./services/payment-gateway-go/internal/handlers/payment.go

```
package handlers
import ("encoding/json"; "net/http"; "sync"; "github.com/ffarena/payment-gateway-go/internal/manager"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/providers"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type PaymentHandler struct{manager *manager.Manager; store storage.Store; metrics observability.Metrics; mu sync.RWMutex; idempotency map[string]*models.Payment}
func NewPaymentHandler(mgr *manager.Manager, store storage.Store, metrics observability.Metrics) *PaymentHandler {return &PaymentHandler{manager:mgr,store:store,metrics:metrics,idempotency:make(map[string]*models.Payment)}}
func (h *PaymentHandler) ListMethods(w http.ResponseWriter, r *http.Request){methods:=h.manager.List(); w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(map[string]interface{}{"methods":methods,"count":len(methods)})}
func (h *PaymentHandler) CreatePayment(w http.ResponseWriter, r *http.Request){
    var req providers.CreatePaymentRequest
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    idempotencyKey:=r.Header.Get("Idempotency-Key")
    if idempotencyKey==""{idempotencyKey=r.Header.Get("X-Idempotency-Key")}
    if idempotencyKey==""{idempotencyKey=uuid.New().String()}
    h.mu.RLock()
    if existing,ok:=h.idempotency[idempotencyKey]; ok{h.mu.RUnlock(); w.Header().Set("Content-Type","application/json"); w.WriteHeader(200); json.NewEncoder(w).Encode(existing); return}
    h.mu.RUnlock()
    provider,ok:=h.manager.Get(req.Provider)
    if !ok{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"unsupported_provider"}); return}
    if !provider.SupportsCurrency(req.Currency){w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"currency_not_supported"}); return}
    resp,err:=provider.CreatePayment(r.Context(),req)
    if err!=nil{w.WriteHeader(500); json.NewEncoder(w).Encode(map[string]string{"error":err.Error()}); return}
    payment:=&models.Payment{ID:uuid.New().String(),UserID:req.UserID,Provider:req.Provider,ExternalID:req.ExternalID,AmountMinor:req.AmountMinor,Currency:req.Currency,Status:resp.Status,IdempotencyKey:idempotencyKey}
    h.mu.Lock(); h.idempotency[idempotencyKey]=payment; h.mu.Unlock()
    h.metrics.Increment("payment.created",map[string]string{"provider":req.Provider})
    w.Header().Set("Content-Type","application/json"); w.WriteHeader(201); json.NewEncoder(w).Encode(payment)
}
func (h *PaymentHandler) QueryPayment(w http.ResponseWriter, r *http.Request){
    externalID:=r.URL.Query().Get("external_id")
    if externalID==""{externalID=r.PathValue("id"); if externalID==""{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"external_id required"}); return}}
    h.mu.RLock()
    for _,p:=range h.idempotency{if p.ExternalID==externalID{h.mu.RUnlock(); w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(p); return}}
    h.mu.RUnlock()
    w.WriteHeader(404); json.NewEncoder(w).Encode(map[string]string{"error":"payment_not_found"})
}
```

## File: ./services/payment-gateway-go/internal/handlers/payout.go

```
package handlers
import ("encoding/json"; "net/http"; "sync"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/google/uuid")
type PayoutHandler struct{metrics observability.Metrics; mu sync.RWMutex; payouts map[string]*models.Payout; byExternal map[string]*models.Payout; byIdempotency map[string]*models.Payout}
func NewPayoutHandler(metrics observability.Metrics) *PayoutHandler {return &PayoutHandler{metrics:metrics,payouts:make(map[string]*models.Payout),byExternal:make(map[string]*models.Payout),byIdempotency:make(map[string]*models.Payout)}}
func (h *PayoutHandler) Create(w http.ResponseWriter, r *http.Request){
    var req models.Payout
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    if req.IdempotencyKey==""{req.IdempotencyKey=uuid.New().String()}
    if req.ExternalID==""{req.ExternalID=uuid.New().String()}
    if req.ID==""{req.ID=uuid.New().String()}
    req.Status="pending"
    h.mu.Lock(); defer h.mu.Unlock()
    if existing,ok:=h.byIdempotency[req.IdempotencyKey]; ok{w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(existing); return}
    if _,ok:=h.byExternal[req.ExternalID]; ok{w.WriteHeader(409); json.NewEncoder(w).Encode(map[string]string{"error":"external_id exists"}); return}
    h.payouts[req.ID]=&req; h.byExternal[req.ExternalID]=&req; h.byIdempotency[req.IdempotencyKey]=&req
    h.metrics.Increment("payout.created",nil)
    w.Header().Set("Content-Type","application/json"); w.WriteHeader(201); json.NewEncoder(w).Encode(req)
}
```

## File: ./services/payment-gateway-go/internal/handlers/wallet.go

```
package handlers
import ("encoding/json"; "net/http"; "sync"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type WalletHandler struct{store storage.Store; metrics observability.Metrics; mu sync.RWMutex; wallets map[string]*models.Wallet; ledger map[int64][]*models.LedgerEntry}
func NewWalletHandler(store storage.Store, metrics observability.Metrics) *WalletHandler {return &WalletHandler{store:store,metrics:metrics,wallets:make(map[string]*models.Wallet),ledger:make(map[int64][]*models.LedgerEntry)}}
type CreditRequest struct{UserID int64 `json:"user_id"`; AmountMinor int64 `json:"amount_minor"`; Currency string `json:"currency"`; ReferenceType string `json:"reference_type"`; ReferenceID string `json:"reference_id"`; IdempotencyKey string `json:"idempotency_key"`}
func (h *WalletHandler) Credit(w http.ResponseWriter, r *http.Request){
    var req CreditRequest
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    if req.AmountMinor<=0{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"amount must be positive"}); return}
    key:=req.IdempotencyKey; if key==""{key=uuid.New().String()}
    h.mu.Lock(); defer h.mu.Unlock()
    walletKey:=string(rune(req.UserID))+":"+req.Currency
    wallet,ok:=h.wallets[walletKey]
    if !ok{wallet=&models.Wallet{ID:int64(len(h.wallets)+1),UserID:req.UserID,Currency:req.Currency,BalanceMinor:0}; h.wallets[walletKey]=wallet}
    for _,entry:=range h.ledger[wallet.ID]{if entry.IdempotencyKey==key{w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry); return}}
    newBalance:=wallet.BalanceMinor+req.AmountMinor
    entry:=&models.LedgerEntry{ID:int64(len(h.ledger[wallet.ID])+1),WalletID:wallet.ID,UserID:req.UserID,Direction:"credit",AmountMinor:req.AmountMinor,BalanceAfterMinor:newBalance,ReferenceType:req.ReferenceType,ReferenceID:req.ReferenceID,IdempotencyKey:key}
    h.ledger[wallet.ID]=append(h.ledger[wallet.ID],entry)
    wallet.BalanceMinor=newBalance
    h.metrics.Increment("wallet.credit",map[string]string{"currency":req.Currency})
    w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry)
}
func (h *WalletHandler) Debit(w http.ResponseWriter, r *http.Request){
    var req CreditRequest
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    if req.AmountMinor<=0{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"amount must be positive"}); return}
    key:=req.IdempotencyKey; if key==""{key=uuid.New().String()}
    h.mu.Lock(); defer h.mu.Unlock()
    walletKey:=string(rune(req.UserID))+":"+req.Currency
    wallet,ok:=h.wallets[walletKey]
    if !ok{w.WriteHeader(404); json.NewEncoder(w).Encode(map[string]string{"error":"wallet_not_found"}); return}
    if wallet.BalanceMinor<req.AmountMinor{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"insufficient_funds"}); return}
    for _,entry:=range h.ledger[wallet.ID]{if entry.IdempotencyKey==key{w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry); return}}
    newBalance:=wallet.BalanceMinor-req.AmountMinor
    entry:=&models.LedgerEntry{ID:int64(len(h.ledger[wallet.ID])+1),WalletID:wallet.ID,UserID:req.UserID,Direction:"debit",AmountMinor:req.AmountMinor,BalanceAfterMinor:newBalance,ReferenceType:req.ReferenceType,ReferenceID:req.ReferenceID,IdempotencyKey:key}
    h.ledger[wallet.ID]=append(h.ledger[wallet.ID],entry)
    wallet.BalanceMinor=newBalance
    h.metrics.Increment("wallet.debit",map[string]string{"currency":req.Currency})
    w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry)
}
func (h *WalletHandler) GetBalance(w http.ResponseWriter, r *http.Request){w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(map[string]interface{}{"balance_minor":0,"currency":"BDT"})}
```

## File: ./services/payment-gateway-go/internal/handlers/webhook.go

```
package handlers
import ("encoding/json"; "net/http"; "sync"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/google/uuid")
type WebhookHandler struct{metrics observability.Metrics; mu sync.RWMutex; events map[string]*domain.WebhookEvent}
func NewWebhookHandler(metrics observability.Metrics) *WebhookHandler {return &WebhookHandler{metrics:metrics,events:make(map[string]*domain.WebhookEvent)}}
func (h *WebhookHandler) Inbound(w http.ResponseWriter, r *http.Request){
    provider:=r.PathValue("provider"); if provider==""{provider="unknown"}
    var payload map[string]interface{}
    if err:=json.NewDecoder(r.Body).Decode(&payload); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_payload"}); return}
    eventID,_:=payload["event_id"].(string)
    if eventID==""{eventID,_=payload["trxID"].(string)}
    if eventID==""{eventID,_=payload["paymentRefId"].(string)}
    if eventID==""{eventID,_=payload["txnId"].(string)}
    if eventID==""{eventID=uuid.New().String()}
    h.mu.Lock(); defer h.mu.Unlock()
    if existing,ok:=h.events[eventID]; ok{existing.Attempts++; w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(map[string]interface{}{"status":"duplicate","event_id":eventID}); return}
    event:=domain.NewWebhookEvent(provider,"payment.succeeded",eventID,payload); event.ID=uuid.New().String(); h.events[eventID]=event
    h.metrics.Increment("webhook.received",map[string]string{"provider":provider})
    w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(map[string]interface{}{"status":"received","event_id":eventID})
}
func (h *WebhookHandler) IsDuplicate(eventID string) bool{h.mu.RLock(); defer h.mu.RUnlock(); _,ok:=h.events[eventID]; return ok}
```

## File: ./services/payment-gateway-go/internal/health/service.go

```
package health
import ("context"; "time"; "github.com/ffarena/payment-gateway-go/internal/config"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage")
type Service struct{config *config.Config; store storage.Store; metrics observability.Metrics}
func NewService(cfg *config.Config, store storage.Store, metrics observability.Metrics) *Service {return &Service{config:cfg,store:store,metrics:metrics}}
type Health struct{Status string `json:"status"`; Service string `json:"service"`; Version string `json:"version"`; Env string `json:"env"`; Timestamp string `json:"timestamp"`; Checks map[string]string `json:"checks,omitempty"`}
func (s *Service) Check(ctx context.Context) *Health{
    checks:=make(map[string]string)
    if err:=s.store.HealthCheck(ctx); err!=nil{checks["database"]="down: "+err.Error()} else {checks["database"]="ok"}
    status:="ok"; for _,v:=range checks{if v!="ok"{status="degraded"}}
    return &Health{Status:status,Service:s.config.ServiceID,Version:s.config.Version,Env:s.config.Env,Timestamp:time.Now().UTC().Format(time.RFC3339),Checks:checks}
}
func (s *Service) Live() *Health{return &Health{Status:"ok",Service:s.config.ServiceID,Version:s.config.Version,Env:s.config.Env,Timestamp:time.Now().UTC().Format(time.RFC3339)}}
func (s *Service) Ready(ctx context.Context) (*Health, int){
    h:=s.Check(ctx)
    code:=200; if h.Status!="ok"{code=503}
    return h, code
}
```

## File: ./services/payment-gateway-go/internal/idempotency/service.go

```
package idempotency
import ("context"; "crypto/sha256"; "encoding/json"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type Service struct{store storage.Store}
func NewService(store storage.Store) *Service {return &Service{store:store}}
func (s *Service) GenerateKey() string {return uuid.New().String()}
func (s *Service) HashRequest(data interface{}) (string, error){b,err:=json.Marshal(data); if err!=nil{return "", err}; h:=sha256.Sum256(b); return fmt.Sprintf("%x",h), nil}
func (s *Service) Check(ctx context.Context, key string, requestBody map[string]interface{}) (*domain.IdempotencyRecord, error){
    record,err:=s.store.GetIdempotency(ctx,key)
    if err!=nil{return nil, nil}
    fp,err:=s.HashRequest(requestBody)
    if err!=nil{return nil, err}
    if record.Fingerprint!=fp{return nil, fmt.Errorf("idempotency key exists with different fingerprint")}
    if record.ExpiresAt.Before(time.Now()){s.store.DeleteIdempotency(ctx,key); return nil, nil}
    return record, nil
}
func (s *Service) Save(ctx context.Context, key, operation string, requestBody, responseBody map[string]interface{}, statusCode int) error{
    fp,err:=s.HashRequest(requestBody)
    if err!=nil{return err}
    record:=&domain.IdempotencyRecord{Key:key,Fingerprint:fp,Operation:operation,RequestBody:requestBody,ResponseBody:responseBody,StatusCode:&statusCode,ExpiresAt:time.Now().Add(24*time.Hour),CreatedAt:time.Now(),UpdatedAt:time.Now()}
    return s.store.SetIdempotency(ctx,record)
}
func (s *Service) Delete(ctx context.Context, key string) error{return s.store.DeleteIdempotency(ctx,key)}
func (s *Service) Cleanup(ctx context.Context) error{return s.store.CleanupIdempotency(ctx)}
```

## File: ./services/payment-gateway-go/internal/manager/manager.go

```
package manager
import ("context"; "sync"; "github.com/ffarena/payment-gateway-go/internal/providers")
type Manager struct{mu sync.RWMutex; providers map[string]providers.Provider}
func New() *Manager {return &Manager{providers: make(map[string]providers.Provider)}}
func (m *Manager) Register(key string, p providers.Provider){m.mu.Lock(); defer m.mu.Unlock(); m.providers[key]=p}
func (m *Manager) Unregister(key string){m.mu.Lock(); defer m.mu.Unlock(); delete(m.providers,key)}
func (m *Manager) Get(key string) (providers.Provider, bool){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.providers[key]; return p,ok}
func (m *Manager) MustGet(key string) providers.Provider{p,ok:=m.Get(key); if !ok{panic("provider not found: "+key)}; return p}
func (m *Manager) Has(key string) bool{m.mu.RLock(); defer m.mu.RUnlock(); _,ok:=m.providers[key]; return ok}
func (m *Manager) Count() int{m.mu.RLock(); defer m.mu.RUnlock(); return len(m.providers)}
func (m *Manager) List() []string{m.mu.RLock(); defer m.mu.RUnlock(); keys:=make([]string,0,len(m.providers)); for k:=range m.providers{keys=append(keys,k)}; return keys}
func (m *Manager) SupportsCurrency(currency string) []string{m.mu.RLock(); defer m.mu.RUnlock(); var result []string; for key,p:=range m.providers{if p.SupportsCurrency(currency){result=append(result,key)}}; return result}
func (m *Manager) HealthCheck(ctx context.Context) map[string]error{m.mu.RLock(); defer m.mu.RUnlock(); results:=make(map[string]error); for key,p:=range m.providers{results[key]=p.HealthCheck(ctx)}; return results}
func (m *Manager) Metadata() map[string]map[string]interface{}{m.mu.RLock(); defer m.mu.RUnlock(); result:=make(map[string]map[string]interface{}); for key,p:=range m.providers{result[key]=p.Metadata()}; return result}
```

## File: ./services/payment-gateway-go/internal/middleware/audit_log.go

```
package middleware
import ("log"; "net/http"; "time")
func AuditLog(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        start:=time.Now()
        requestID:=r.Header.Get("X-Request-ID")
        next.ServeHTTP(w,r)
        log.Printf(`{"level":"audit","service":"payment-gateway-go","action":"%s %s","request_id":"%s","ip":"%s","timestamp":"%s"}`,r.Method,r.URL.Path,requestID,r.RemoteAddr,start.Format(time.RFC3339))
    })
}
```

