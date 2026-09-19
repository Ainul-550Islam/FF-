package middleware
import ("log"; "net/http"; "runtime/debug")
func Recovery(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        defer func(){
            if rec:=recover(); rec!=nil{
                log.Printf(`{"level":"error","service":"payment-gateway-go","error":"panic recovered","panic":"%v","stack":"%s","request_id":"%s"}`,rec,string(debug.Stack()),r.Header.Get("X-Request-ID"))
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(500)
                w.Write([]byte(`{"error":"internal_server_error"}`))
            }
        }()
        next.ServeHTTP(w,r)
    })
}
