package middleware
import ("net/http"; "github.com/ffarena/payment-gateway-go/internal/config")
func FeatureFlag(flagManager *config.FeatureFlagManager, flag string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
            if !flagManager.IsEnabled(flag){
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(404)
                w.Write([]byte(`{"error":"not_found"}`))
                return
            }
            next.ServeHTTP(w,r)
        })
    }
}
