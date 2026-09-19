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
