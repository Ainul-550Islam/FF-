package middleware

import (
    "net/http"
    "strings"

    "github.com/ffarena/payment-gateway-go/internal/security"
)

// BearerAuth verifies JWT bearer tokens against secret, skipping skipPaths
// (R9: the old len(token)<10 gate accepted any long-enough string without
// verifying anything). Mount only with a configured secret. Currently
// unmounted — service auth covers the daemon's callers.
func BearerAuth(secret string, skipPaths []string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
            for _, p := range skipPaths {
                if strings.HasPrefix(r.URL.Path, p) {
                    next.ServeHTTP(w, r)
                    return
                }
            }
            auth := r.Header.Get("Authorization")
            if auth == "" || !strings.HasPrefix(auth, "Bearer ") {
                w.Header().Set("Content-Type", "application/json")
                w.WriteHeader(401)
                w.Write([]byte(`{"error":"unauthorized","message":"Bearer token required"}`))
                return
            }
            token := strings.TrimPrefix(auth, "Bearer ")
            if _, err := security.VerifyJWT(token, secret); err != nil {
                w.Header().Set("Content-Type", "application/json")
                w.WriteHeader(401)
                w.Write([]byte(`{"error":"invalid_token"}`))
                return
            }
            next.ServeHTTP(w, r)
        })
    }
}
