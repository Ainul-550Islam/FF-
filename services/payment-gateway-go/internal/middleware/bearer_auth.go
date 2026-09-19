package middleware
import ("net/http"; "strings")
func BearerAuth(skipPaths []string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
            for _,p:=range skipPaths{if strings.HasPrefix(r.URL.Path,p){next.ServeHTTP(w,r); return}}
            auth:=r.Header.Get("Authorization")
            if auth==""||!strings.HasPrefix(auth,"Bearer "){
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(401)
                w.Write([]byte(`{"error":"unauthorized","message":"Bearer token required"}`))
                return
            }
            token:=strings.TrimPrefix(auth,"Bearer ")
            if len(token)<10{
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(401)
                w.Write([]byte(`{"error":"invalid_token"}`))
                return
            }
            next.ServeHTTP(w,r)
        })
    }
}
