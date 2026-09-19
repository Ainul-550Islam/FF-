package middleware
import ("log"; "net/http"; "time")
func StructuredLog(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        start:=time.Now()
        requestID:=r.Header.Get("X-Request-ID")
        rw:=&responseWriter{ResponseWriter:w,statusCode:200}
        next.ServeHTTP(rw,r)
        latency:=time.Since(start).Milliseconds()
        log.Printf(`{"level":"info","service":"payment-gateway-go","method":"%s","path":"%s","status":%d,"latency_ms":%d,"request_id":"%s"}`,r.Method,r.URL.Path,rw.statusCode,latency,requestID)
    })
}
type responseWriter struct{http.ResponseWriter; statusCode int}
func (rw *responseWriter) WriteHeader(code int){rw.statusCode=code; rw.ResponseWriter.WriteHeader(code)}
