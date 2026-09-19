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
