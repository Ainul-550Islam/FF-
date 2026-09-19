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
