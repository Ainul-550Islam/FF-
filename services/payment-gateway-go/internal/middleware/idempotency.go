package middleware
import "net/http"
func Idempotency(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        if r.Method=="POST"{
            key:=r.Header.Get("Idempotency-Key")
            if key==""{key=r.Header.Get("X-Idempotency-Key")}
            if key!=""&&len(key)<8{
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(400)
                w.Write([]byte(`{"error":"invalid_idempotency_key"}`))
                return
            }
        }
        next.ServeHTTP(w,r)
    })
}
