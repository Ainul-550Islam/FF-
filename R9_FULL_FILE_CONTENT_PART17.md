# R9 Full File Content Part 17 - Files 241-255

Total files in this part: 15

## File: ./services/payment-gateway-go/internal/models/wallet.go

```
package models
type WalletBalance struct{WalletID int64 `json:"wallet_id"`; BalanceMinor int64 `json:"balance_minor"`}
func CalculateBalance(credits, debits int64) int64 {return credits-debits}
func VerifyLedgerIntegrity(entries []LedgerEntry, walletBalance int64) bool {
    running:=int64(0)
    for _,e:=range entries{
        if e.Direction=="credit"{running+=e.AmountMinor} else {running-=e.AmountMinor}
        if running!=e.BalanceAfterMinor{return false}
    }
    return running==walletBalance
}
```

## File: ./services/payment-gateway-go/internal/observability/health.go

```
package observability
import ("encoding/json"; "net/http"; "sync"; "time")
type HealthStatus string
const (StatusOK HealthStatus="ok"; StatusDegraded HealthStatus="degraded"; StatusDown HealthStatus="down")
type CheckResult struct{Status HealthStatus `json:"status"`; Message string `json:"message,omitempty"`; Latency int64 `json:"latency_ms,omitempty"`}
type HealthChecker struct{mu sync.RWMutex; checks map[string]func() CheckResult}
func NewHealthChecker() *HealthChecker {return &HealthChecker{checks: make(map[string]func() CheckResult)}}
func (h *HealthChecker) Register(name string, check func() CheckResult){h.mu.Lock(); defer h.mu.Unlock(); h.checks[name]=check}
func (h *HealthChecker) Check() (HealthStatus, map[string]CheckResult){
    h.mu.RLock(); defer h.mu.RUnlock()
    results:=make(map[string]CheckResult); overall:=StatusOK
    for name,check:=range h.checks{
        start:=time.Now(); result:=check(); result.Latency=time.Since(start).Milliseconds(); results[name]=result
        if result.Status==StatusDown{overall=StatusDown} else if result.Status==StatusDegraded&&overall!=StatusDown{overall=StatusDegraded}
    }
    return overall, results
}
func (h *HealthChecker) LiveHandler(w http.ResponseWriter, r *http.Request){
    w.Header().Set("Content-Type","application/json"); w.WriteHeader(200)
    json.NewEncoder(w).Encode(map[string]interface{}{"status":"ok","service":"payment-gateway-go","timestamp":time.Now().UTC().Format(time.RFC3339)})
}
func (h *HealthChecker) ReadyHandler(w http.ResponseWriter, r *http.Request){
    status,checks:=h.Check()
    code:=200; if status==StatusDown{code=503}
    w.Header().Set("Content-Type","application/json"); w.WriteHeader(code)
    json.NewEncoder(w).Encode(map[string]interface{}{"status":status,"service":"payment-gateway-go","checks":checks,"timestamp":time.Now().UTC().Format(time.RFC3339)})
}
func (h *HealthChecker) MetricsHandler(metrics Metrics) http.HandlerFunc {
    return func(w http.ResponseWriter, r *http.Request){
        w.Header().Set("Content-Type","application/json")
        counters:=metrics.AllCounters()
        json.NewEncoder(w).Encode(map[string]interface{}{"counters":counters,"timestamp":time.Now().UTC().Format(time.RFC3339)})
    }
}
```

## File: ./services/payment-gateway-go/internal/observability/logger.go

```
package observability
import ("encoding/json"; "log"; "strings")
type Logger struct{service string; env string; version string}
func NewLogger(service, env, version string) *Logger {return &Logger{service:service,env:env,version:version}}
func (l *Logger) Info(msg string, fields map[string]interface{}){l.log("info",msg,fields)}
func (l *Logger) Error(msg string, fields map[string]interface{}){l.log("error",msg,fields)}
func (l *Logger) Audit(action string, fields map[string]interface{}){fields["action"]=action; l.log("audit",action,fields)}
func (l *Logger) log(level, msg string, fields map[string]interface{}){
    if fields==nil{fields=make(map[string]interface{})}
    fields["level"]=level; fields["service"]=l.service; fields["env"]=l.env; fields["version"]=l.version; fields["message"]=l.redact(msg)
    for k,v:=range fields{if isSensitive(k){fields[k]="***REDACTED***"} else if s,ok:=v.(string); ok&&isSensitiveValue(s){fields[k]="***REDACTED***"}}
    b,_:=json.Marshal(fields); log.Println(string(b))
}
func (l *Logger) redact(s string) string{
    sensitive:=[]string{"password","secret","token","jwt","DATABASE_URL","REDIS_URL"}
    for _,key:=range sensitive{if strings.Contains(strings.ToLower(s),strings.ToLower(key)){return "***REDACTED***"}}
    return s
}
func (l *Logger) WithRequestID(requestID string) *Logger {return l}
func isSensitive(key string) bool{
    lower:=strings.ToLower(key)
    sensitive:=[]string{"password","secret","token","jwt","api_key","private_key","database_url","redis_url"}
    for _,s:=range sensitive{if strings.Contains(lower,s){return true}}
    return false
}
func isSensitiveValue(v string) bool{if len(v)>20&&(strings.HasPrefix(v,"eyJ")||strings.HasPrefix(v,"sk_")){return true}; return false}
```

## File: ./services/payment-gateway-go/internal/observability/metrics.go

```
package observability
import "sync"
type Metrics interface{Increment(name string, tags map[string]string); Gauge(name string, value float64, tags map[string]string); Timing(name string, durationMs int64, tags map[string]string); GetCounter(name string) int64; AllCounters() map[string]int64}
type NullMetrics struct{}
func (n *NullMetrics) Increment(name string, tags map[string]string){}
func (n *NullMetrics) Gauge(name string, value float64, tags map[string]string){}
func (n *NullMetrics) Timing(name string, durationMs int64, tags map[string]string){}
func (n *NullMetrics) GetCounter(name string) int64 {return 0}
func (n *NullMetrics) AllCounters() map[string]int64 {return map[string]int64{}}
type InMemoryMetrics struct{mu sync.RWMutex; counters map[string]int64; gauges map[string]float64; timings map[string][]int64}
func NewInMemoryMetrics() *InMemoryMetrics {return &InMemoryMetrics{counters: make(map[string]int64), gauges: make(map[string]float64), timings: make(map[string][]int64)}}
func (m *InMemoryMetrics) Increment(name string, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.counters[name]++}
func (m *InMemoryMetrics) Gauge(name string, value float64, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.gauges[name]=value}
func (m *InMemoryMetrics) Timing(name string, durationMs int64, tags map[string]string){m.mu.Lock(); defer m.mu.Unlock(); m.timings[name]=append(m.timings[name],durationMs)}
func (m *InMemoryMetrics) GetCounter(name string) int64{m.mu.RLock(); defer m.mu.RUnlock(); return m.counters[name]}
func (m *InMemoryMetrics) AllCounters() map[string]int64{m.mu.RLock(); defer m.mu.RUnlock(); result:=make(map[string]int64); for k,v:=range m.counters{result[k]=v}; return result}
```

## File: ./services/payment-gateway-go/internal/observability/tracer.go

```
package observability
import ("context"; "github.com/google/uuid")
type Span struct{TraceID string `json:"trace_id"`; SpanID string `json:"span_id"`; Operation string `json:"operation"`}
func NewSpan(operation string) *Span {return &Span{TraceID:uuid.New().String(),SpanID:uuid.New().String()[:8],Operation:operation}}
func (s *Span) Finish(){}
func FromContext(ctx context.Context) *Span{if span,ok:=ctx.Value("span").(*Span); ok{return span}; return NewSpan("unknown")}
func WithSpan(ctx context.Context, span *Span) context.Context {return context.WithValue(ctx,"span",span)}
```

## File: ./services/payment-gateway-go/internal/provider_registry/registry.go

```
package provider_registry
import "github.com/ffarena/payment-gateway-go/internal/providers"
type Registry struct{factory *providers.Factory; providers map[string]providers.Provider}
func NewRegistry(factory *providers.Factory) *Registry {return &Registry{factory:factory,providers:make(map[string]providers.Provider)}}
func (r *Registry) Discover() error{
    for _,key:=range r.factory.SupportedProviders(){
        p,err:=r.factory.Create(key)
        if err!=nil{continue}
        r.providers[key]=p
    }
    return nil
}
func (r *Registry) Get(key string) (providers.Provider, bool){p,ok:=r.providers[key]; return p,ok}
func (r *Registry) List() []string{keys:=make([]string,0,len(r.providers)); for k:=range r.providers{keys=append(keys,k)}; return keys}
func (r *Registry) Count() int{return len(r.providers)}
```

## File: ./services/payment-gateway-go/internal/providers/base.go

```
package providers
import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "github.com/google/uuid"
)
type ProviderConfig struct{BaseURL string `json:"base_url"`; Secret string `json:"-"`; MerchantID string `json:"merchant_id"`; StoreID string `json:"store_id"`; TimeoutMs int `json:"timeout_ms"`; Sandbox bool `json:"sandbox"`}
type BaseProvider struct{Config ProviderConfig}
func (b *BaseProvider) GenerateExternalID() string {return uuid.New().String()}
func (b *BaseProvider) CreateBasePayment(req CreatePaymentRequest) CreatePaymentResponse {
    return CreatePaymentResponse{ExternalID:req.ExternalID,ProviderReference:b.GenerateExternalID(),Status:"pending",Metadata:map[string]interface{}{"provider":req.Provider,"currency":req.Currency}}
}
func (b *BaseProvider) VerifyHMAC(payload []byte, signature, secret string) error {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write(payload)
    expected:=hex.EncodeToString(mac.Sum(nil))
    if !hmac.Equal([]byte(expected),[]byte(signature)){return fmt.Errorf("invalid HMAC signature")}
    return nil
}
func GenerateHMAC(secret, message string) string {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write([]byte(message)); return hex.EncodeToString(mac.Sum(nil))
}
func (b *BaseProvider) MarshalPayload(v interface{}) ([]byte, error) {return json.Marshal(v)}
```

## File: ./services/payment-gateway-go/internal/providers/bkash.go

```
package providers
import ("context"; "errors")
type BkashProvider struct{BaseProvider}
func NewBkashProvider(cfg ProviderConfig) *BkashProvider {return &BkashProvider{BaseProvider:BaseProvider{Config:cfg}}}
func (p *BkashProvider) Key() string {return "bkash"}
func (p *BkashProvider) Label() string {return "bKash"}
func (p *BkashProvider) SupportsCurrency(currency string) bool {return currency=="BDT"}
func (p *BkashProvider) SupportsRefund() bool {return true}
func (p *BkashProvider) Capabilities() []string {return []string{"create","query","refund","webhook"}}
func (p *BkashProvider) Metadata() map[string]interface{} {return map[string]interface{}{"type":"mobile_banking","currency":"BDT","min_amount":10,"max_amount":25000}}
func (p *BkashProvider) ValidateConfig() error {if p.Config.MerchantID==""{return errors.New("bkash merchant_id required")}; return nil}
func (p *BkashProvider) HealthCheck(ctx context.Context) error {return nil}
func (p *BkashProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {
    if req.AmountMinor<1000{return nil, errors.New("bkash minimum 10 BDT")}
    if req.AmountMinor>2500000{return nil, errors.New("bkash maximum 25000 BDT")}
    resp:=p.CreateBasePayment(req)
    resp.Metadata=map[string]interface{}{"trxID":resp.ProviderReference,"amount":req.AmountMinor,"currency":"BDT","intent":"sale"}
    resp.Status="pending"; return &resp, nil
}
func (p *BkashProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {return &QueryPaymentResponse{Status:"pending",ProviderReference:req.ProviderReference}, nil}
func (p *BkashProvider) VerifyWebhook(payload []byte, signature string) error {return p.VerifyHMAC(payload,signature,p.Config.Secret)}
func (p *BkashProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {
    trxID,_:=payload["trxID"].(string)
    if trxID==""{trxID,_=payload["transactionId"].(string)}
    return &QueryPaymentResponse{Status:"succeeded",ProviderReference:trxID}, nil
}
func (p *BkashProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {return &RefundResponse{RefundID:p.GenerateExternalID(),Status:"pending"}, nil}
```

## File: ./services/payment-gateway-go/internal/providers/factory.go

```
package providers
type Factory struct{configs map[string]ProviderConfig}
func NewFactory(configs map[string]ProviderConfig) *Factory {return &Factory{configs:configs}}
func (f *Factory) Create(key string) (Provider, error) {
    cfg,ok:=f.configs[key]
    if !ok{cfg=ProviderConfig{}}
    switch key{
    case "manual": return NewManualProvider(cfg), nil
    case "bkash": return NewBkashProvider(cfg), nil
    case "nagad": return NewNagadProvider(cfg), nil
    case "rocket": return NewRocketProvider(cfg), nil
    default: return nil, &ProviderNotFoundError{Key:key}
    }
}
func (f *Factory) SupportedProviders() []string {return []string{"manual","bkash","nagad","rocket"}}
func (f *Factory) IsSupported(key string) bool {for _,k:=range f.SupportedProviders(){if k==key{return true}}; return false}
func (f *Factory) CreateAll() map[string]Provider {result:=make(map[string]Provider); for _,key:=range f.SupportedProviders(){if p,err:=f.Create(key); err==nil{result[key]=p}}; return result}
type ProviderNotFoundError struct{Key string}
func (e *ProviderNotFoundError) Error() string {return "provider not found: "+e.Key}
```

## File: ./services/payment-gateway-go/internal/providers/interface.go

```
package providers
import "context"
type CreatePaymentRequest struct {
    UserID int64 `json:"user_id"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Provider string `json:"provider"`
    ExternalID string `json:"external_id"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
}
type CreatePaymentResponse struct {
    ExternalID string `json:"external_id"`
    ProviderReference string `json:"provider_reference"`
    Status string `json:"status"`
    RedirectURL *string `json:"redirect_url,omitempty"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
}
type QueryPaymentRequest struct{ExternalID string `json:"external_id"`; ProviderReference string `json:"provider_reference"`}
type QueryPaymentResponse struct{Status string `json:"status"`; ProviderReference string `json:"provider_reference"`; AmountMinor int64 `json:"amount_minor"`; Metadata map[string]interface{} `json:"metadata,omitempty"`}
type RefundRequest struct{PaymentID string `json:"payment_id"`; ExternalID string `json:"external_id"`; AmountMinor int64 `json:"amount_minor"`; Currency string `json:"currency"`; IdempotencyKey string `json:"idempotency_key"`}
type RefundResponse struct{RefundID string `json:"refund_id"`; Status string `json:"status"`}
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
}
```

## File: ./services/payment-gateway-go/internal/providers/manual.go

```
package providers
import "context"
type ManualProvider struct{BaseProvider}
func NewManualProvider(cfg ProviderConfig) *ManualProvider {return &ManualProvider{BaseProvider:BaseProvider{Config:cfg}}}
func (p *ManualProvider) Key() string {return "manual"}
func (p *ManualProvider) Label() string {return "Manual Payment"}
func (p *ManualProvider) SupportsCurrency(currency string) bool {return currency=="BDT"||currency=="USD"||currency=="EUR"}
func (p *ManualProvider) SupportsRefund() bool {return true}
func (p *ManualProvider) Capabilities() []string {return []string{"create","query","refund","manual_review"}}
func (p *ManualProvider) Metadata() map[string]interface{} {return map[string]interface{}{"type":"manual","currencies":[]string{"BDT","USD","EUR"}}}
func (p *ManualProvider) ValidateConfig() error {return nil}
func (p *ManualProvider) HealthCheck(ctx context.Context) error {return nil}
func (p *ManualProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {resp:=p.CreateBasePayment(req); resp.Status="succeeded"; return &resp, nil}
func (p *ManualProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {return &QueryPaymentResponse{Status:"succeeded",ProviderReference:req.ProviderReference,AmountMinor:0}, nil}
func (p *ManualProvider) VerifyWebhook(payload []byte, signature string) error {return p.VerifyHMAC(payload,signature,p.Config.Secret)}
func (p *ManualProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {return &QueryPaymentResponse{Status:"succeeded",AmountMinor:0}, nil}
func (p *ManualProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {return &RefundResponse{RefundID:p.GenerateExternalID(),Status:"succeeded"}, nil}
```

## File: ./services/payment-gateway-go/internal/providers/nagad.go

```
package providers
import ("context"; "errors")
type NagadProvider struct{BaseProvider}
func NewNagadProvider(cfg ProviderConfig) *NagadProvider {return &NagadProvider{BaseProvider:BaseProvider{Config:cfg}}}
func (p *NagadProvider) Key() string {return "nagad"}
func (p *NagadProvider) Label() string {return "Nagad"}
func (p *NagadProvider) SupportsCurrency(currency string) bool {return currency=="BDT"}
func (p *NagadProvider) SupportsRefund() bool {return true}
func (p *NagadProvider) Capabilities() []string {return []string{"create","query","refund","webhook"}}
func (p *NagadProvider) Metadata() map[string]interface{} {return map[string]interface{}{"type":"mobile_banking","currency":"BDT"}}
func (p *NagadProvider) ValidateConfig() error {if p.Config.MerchantID==""{return errors.New("nagad merchant_id required")}; return nil}
func (p *NagadProvider) HealthCheck(ctx context.Context) error {return nil}
func (p *NagadProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {resp:=p.CreateBasePayment(req); resp.Metadata=map[string]interface{}{"paymentRefId":resp.ProviderReference,"amount":req.AmountMinor}; resp.Status="pending"; return &resp, nil}
func (p *NagadProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {return &QueryPaymentResponse{Status:"pending",ProviderReference:req.ProviderReference}, nil}
func (p *NagadProvider) VerifyWebhook(payload []byte, signature string) error {return p.VerifyHMAC(payload,signature,p.Config.Secret)}
func (p *NagadProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {ref,_:=payload["paymentRefId"].(string); return &QueryPaymentResponse{Status:"succeeded",ProviderReference:ref}, nil}
func (p *NagadProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {return &RefundResponse{RefundID:p.GenerateExternalID(),Status:"pending"}, nil}
```

## File: ./services/payment-gateway-go/internal/providers/rocket.go

```
package providers
import ("context"; "errors")
type RocketProvider struct{BaseProvider}
func NewRocketProvider(cfg ProviderConfig) *RocketProvider {return &RocketProvider{BaseProvider:BaseProvider{Config:cfg}}}
func (p *RocketProvider) Key() string {return "rocket"}
func (p *RocketProvider) Label() string {return "Rocket"}
func (p *RocketProvider) SupportsCurrency(currency string) bool {return currency=="BDT"}
func (p *RocketProvider) SupportsRefund() bool {return false}
func (p *RocketProvider) Capabilities() []string {return []string{"create","query","webhook"}}
func (p *RocketProvider) Metadata() map[string]interface{} {return map[string]interface{}{"type":"mobile_banking","currency":"BDT","refund_supported":false}}
func (p *RocketProvider) ValidateConfig() error {if p.Config.MerchantID==""{return errors.New("rocket merchant_id required")}; return nil}
func (p *RocketProvider) HealthCheck(ctx context.Context) error {return nil}
func (p *RocketProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {resp:=p.CreateBasePayment(req); resp.Metadata=map[string]interface{}{"txnId":resp.ProviderReference,"amount":req.AmountMinor}; resp.Status="pending"; return &resp, nil}
func (p *RocketProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {return &QueryPaymentResponse{Status:"pending",ProviderReference:req.ProviderReference}, nil}
func (p *RocketProvider) VerifyWebhook(payload []byte, signature string) error {return p.VerifyHMAC(payload,signature,p.Config.Secret)}
func (p *RocketProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {txn,_:=payload["txnId"].(string); return &QueryPaymentResponse{Status:"succeeded",ProviderReference:txn}, nil}
func (p *RocketProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {return nil, errors.New("refund not supported for rocket")}
```

## File: ./services/payment-gateway-go/internal/queue/queue.go

```
package queue
import ("sync"; "time")
type JobType string
const (JobPaymentVerify JobType="payment_verify"; JobWebhookProcess JobType="webhook_process"; JobRefundProcess JobType="refund_process"; JobReconciliation JobType="reconciliation"; JobSettlement JobType="settlement"; JobProviderHealth JobType="provider_health"; JobRetrySchedule JobType="retry_schedule")
type Job struct{ID string `json:"id"`; Type JobType `json:"type"`; Payload map[string]interface{} `json:"payload"`; Attempts int `json:"attempts"`; MaxAttempts int `json:"max_attempts"`; CreatedAt time.Time `json:"created_at"`; NextRetry *time.Time `json:"next_retry,omitempty"`}
type Queue struct{mu sync.RWMutex; jobs map[string]*Job}
func NewQueue() *Queue {return &Queue{jobs: make(map[string]*Job)}}
func (q *Queue) Enqueue(job *Job) error{q.mu.Lock(); defer q.mu.Unlock(); if job.ID==""{job.ID=string(job.Type)+"-"+time.Now().Format("20060102150405")}; job.CreatedAt=time.Now(); if job.MaxAttempts==0{job.MaxAttempts=3}; q.jobs[job.ID]=job; return nil}
func (q *Queue) Dequeue() (*Job, error){q.mu.Lock(); defer q.mu.Unlock(); for _,job:=range q.jobs{if job.NextRetry==nil||time.Now().After(*job.NextRetry){return job, nil}}; return nil, nil}
func (q *Queue) Complete(jobID string) error{q.mu.Lock(); defer q.mu.Unlock(); delete(q.jobs,jobID); return nil}
func (q *Queue) Fail(jobID string, err error) error{q.mu.Lock(); defer q.mu.Unlock(); job,ok:=q.jobs[jobID]; if !ok{return nil}; job.Attempts++; if job.Attempts>=job.MaxAttempts{delete(q.jobs,jobID); return nil}; backoff:=time.Duration(job.Attempts*job.Attempts)*time.Second; next:=time.Now().Add(backoff); job.NextRetry=&next; return nil}
```

## File: ./services/payment-gateway-go/internal/reconciliation/service.go

```
package reconciliation
import ("context"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage")
type Service struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *Service {return &Service{store:store,metrics:metrics,logger:logger}}
type ReconciliationReport struct{Date time.Time `json:"date"`; TotalPayments int `json:"total_payments"`; MismatchedAmount int `json:"mismatched_amount"`; MismatchedStatus int `json:"mismatched_status"`; DuplicateExternal int `json:"duplicate_external"`; LedgerMismatches int `json:"ledger_mismatches"`; Records []domain.ReconciliationRecord `json:"records"`}
func (s *Service) ReconcilePayment(ctx context.Context, payment *models.Payment, providerAmount int64, providerStatus string) (*domain.ReconciliationRecord, error){
    if payment.AmountMinor!=providerAmount{
        record:=&domain.ReconciliationRecord{ID:fmt.Sprintf("rec-%d",time.Now().UnixNano()),PaymentID:payment.ID,Type:domain.ReconciliationTypeAmountMismatch,ExpectedAmount:payment.AmountMinor,ActualAmount:providerAmount,Status:"pending",CreatedAt:time.Now()}
        s.metrics.Increment("reconciliation.amount_mismatch",nil)
        return record, nil
    }
    if payment.Status!=providerStatus{
        record:=&domain.ReconciliationRecord{ID:fmt.Sprintf("rec-%d",time.Now().UnixNano()),PaymentID:payment.ID,Type:domain.ReconciliationTypeStatusMismatch,Status:"pending",CreatedAt:time.Now()}
        s.metrics.Increment("reconciliation.status_mismatch",nil)
        return record, nil
    }
    return nil, nil
}
func (s *Service) GenerateDailyReport(ctx context.Context, date time.Time) (*ReconciliationReport, error){
    report:=&ReconciliationReport{Date:date}
    s.logger.Info("daily reconciliation report generated",map[string]interface{}{"date":date.Format("2006-01-02")})
    return report, nil
}
func (s *Service) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error){return s.store.VerifyLedgerIntegrity(ctx,walletID)}
```

