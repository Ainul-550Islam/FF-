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
