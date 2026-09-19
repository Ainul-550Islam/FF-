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
