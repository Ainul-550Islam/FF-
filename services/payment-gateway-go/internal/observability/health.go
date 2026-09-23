package observability
import ("encoding/json"; "net/http"; "sync"; "time")
type HealthStatus string
const (StatusOK HealthStatus="ok"; StatusDegraded HealthStatus="degraded"; StatusDown HealthStatus="down")
type CheckResult struct {
	Status  HealthStatus           `json:"status"`
	Message string                 `json:"message,omitempty"`
	Latency int64                  `json:"latency_ms,omitempty"`
	Data    map[string]interface{} `json:"data,omitempty"`
}
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
