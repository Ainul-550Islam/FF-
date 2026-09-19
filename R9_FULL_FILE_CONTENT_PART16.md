# R9 Full File Content Part 16 - Files 226-240

Total files in this part: 15

## File: ./services/payment-gateway-go/internal/middleware/bearer_auth.go

```
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
```

## File: ./services/payment-gateway-go/internal/middleware/cors.go

```
package middleware
import "net/http"
func CORS(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        w.Header().Set("Access-Control-Allow-Origin","*")
        w.Header().Set("Access-Control-Allow-Methods","GET, POST, PUT, DELETE, OPTIONS")
        w.Header().Set("Access-Control-Allow-Headers","Authorization, Content-Type, X-Request-ID, Idempotency-Key, X-Idempotency-Key, X-Signature")
        w.Header().Set("Access-Control-Expose-Headers","X-Request-ID")
        if r.Method=="OPTIONS"{w.WriteHeader(200); return}
        next.ServeHTTP(w,r)
    })
}
```

## File: ./services/payment-gateway-go/internal/middleware/feature_flag.go

```
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
```

## File: ./services/payment-gateway-go/internal/middleware/hmac_verify.go

```
package middleware
import ("bytes"; "crypto/hmac"; "crypto/sha256"; "encoding/hex"; "io"; "net/http"; "strings")
func HMACVerify(secret string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
            if !isWebhookPath(r.URL.Path){next.ServeHTTP(w,r); return}
            signature:=r.Header.Get("X-Signature")
            if signature==""{signature=r.Header.Get("X-Webhook-Signature")}
            if signature==""{w.WriteHeader(401); w.Write([]byte(`{"error":"missing_signature"}`)); return}
            body,err:=io.ReadAll(r.Body)
            if err!=nil{w.WriteHeader(400); return}
            r.Body=io.NopCloser(bytes.NewBuffer(body))
            mac:=hmac.New(sha256.New,[]byte(secret))
            mac.Write(body)
            expected:=hex.EncodeToString(mac.Sum(nil))
            if !hmac.Equal([]byte(expected),[]byte(signature)){w.WriteHeader(401); w.Write([]byte(`{"error":"invalid_signature"}`)); return}
            next.ServeHTTP(w,r)
        })
    }
}
func isWebhookPath(path string) bool {return strings.Contains(path,"webhook")}
```

## File: ./services/payment-gateway-go/internal/middleware/idempotency.go

```
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
```

## File: ./services/payment-gateway-go/internal/middleware/json_content.go

```
package middleware
import "net/http"
func JSONContent(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        w.Header().Set("Content-Type","application/json")
        next.ServeHTTP(w,r)
    })
}
```

## File: ./services/payment-gateway-go/internal/middleware/middleware.go

```
package middleware
// Package middleware provides HTTP middleware chain for payment gateway
```

## File: ./services/payment-gateway-go/internal/middleware/rate_limiter.go

```
package middleware
import ("net/http"; "sync"; "time")
type RateLimiter struct{mu sync.Mutex; requests map[string][]time.Time; limit int; window time.Duration}
func NewRateLimiter(limit int, window time.Duration) *RateLimiter {return &RateLimiter{requests: make(map[string][]time.Time), limit: limit, window: window}}
func (rl *RateLimiter) Allow(key string) bool {
    rl.mu.Lock(); defer rl.mu.Unlock()
    now:=time.Now(); cutoff:=now.Add(-rl.window)
    valid:=[]time.Time{}
    for _,t:=range rl.requests[key]{if t.After(cutoff){valid=append(valid,t)}}
    rl.requests[key]=valid
    if len(valid)>=rl.limit{return false}
    rl.requests[key]=append(rl.requests[key],now)
    return true
}
func RateLimitMiddleware(limiter *RateLimiter) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
            ip:=r.RemoteAddr
            if !limiter.Allow(ip){
                w.Header().Set("Content-Type","application/json")
                w.WriteHeader(429)
                w.Write([]byte(`{"error":"rate_limit_exceeded"}`))
                return
            }
            next.ServeHTTP(w,r)
        })
    }
}
```

## File: ./services/payment-gateway-go/internal/middleware/recovery.go

```
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
```

## File: ./services/payment-gateway-go/internal/middleware/request_id.go

```
package middleware
import ("net/http"; "github.com/google/uuid")
func RequestID(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        requestID:=r.Header.Get("X-Request-ID")
        if requestID==""{requestID=uuid.New().String()}
        r.Header.Set("X-Request-ID",requestID)
        w.Header().Set("X-Request-ID",requestID)
        next.ServeHTTP(w,r)
    })
}
```

## File: ./services/payment-gateway-go/internal/middleware/security_headers.go

```
package middleware
import "net/http"
func SecurityHeaders(next http.Handler) http.Handler {
    return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
        w.Header().Set("X-Content-Type-Options","nosniff")
        w.Header().Set("X-Frame-Options","SAMEORIGIN")
        w.Header().Set("X-XSS-Protection","1; mode=block")
        w.Header().Set("Referrer-Policy","strict-origin-when-cross-origin")
        w.Header().Set("Content-Security-Policy","default-src 'none'")
        next.ServeHTTP(w,r)
    })
}
```

## File: ./services/payment-gateway-go/internal/middleware/structured_log.go

```
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
```

## File: ./services/payment-gateway-go/internal/models/ledger.go

```
package models
import "time"
type LedgerIntegrityReport struct {
    WalletID int64 `json:"wallet_id"`
    Calculated int64 `json:"calculated"`
    Stored int64 `json:"stored"`
    IsValid bool `json:"is_valid"`
    CheckedAt time.Time `json:"checked_at"`
    EntryCount int `json:"entry_count"`
    FirstMismatch *int `json:"first_mismatch,omitempty"`
}
```

## File: ./services/payment-gateway-go/internal/models/payment.go

```
package models
import ("errors"; "time")
type Payment struct {
    ID string `json:"id"`
    UserID int64 `json:"user_id"`
    WalletID *int64 `json:"wallet_id,omitempty"`
    Provider string `json:"provider"`
    ExternalID string `json:"external_id"`
    ProviderReference *string `json:"provider_reference,omitempty"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    IdempotencyKey string `json:"idempotency_key"`
    IdempotencyFingerprint string `json:"idempotency_fingerprint,omitempty"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    AuthorizedAt *time.Time `json:"authorized_at,omitempty"`
    SucceededAt *time.Time `json:"succeeded_at,omitempty"`
    FailedAt *time.Time `json:"failed_at,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func (p *Payment) Validate() error {
    if p.AmountMinor<=0{return errors.New("amount must be positive")}
    if p.Provider==""{return errors.New("provider required")}
    if p.ExternalID==""{return errors.New("external_id required")}
    return nil
}
type Wallet struct {
    ID int64 `json:"id"`
    UserID int64 `json:"user_id"`
    Currency string `json:"currency"`
    BalanceMinor int64 `json:"balance_minor"`
    IsLocked bool `json:"is_locked"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
type LedgerEntry struct {
    ID int64 `json:"id"`
    WalletID int64 `json:"wallet_id"`
    UserID int64 `json:"user_id"`
    Direction string `json:"direction"`
    AmountMinor int64 `json:"amount_minor"`
    BalanceAfterMinor int64 `json:"balance_after_minor"`
    ReferenceType string `json:"reference_type"`
    ReferenceID string `json:"reference_id"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CreatedAt time.Time `json:"created_at"`
}
type Payout struct {
    ID string `json:"id"`
    UserID int64 `json:"user_id"`
    TournamentID *int64 `json:"tournament_id,omitempty"`
    AmountMinor int64 `json:"amount_minor"`
    Currency string `json:"currency"`
    Status string `json:"status"`
    ExternalID string `json:"external_id"`
    Provider string `json:"provider"`
    IdempotencyKey string `json:"idempotency_key"`
    Metadata map[string]interface{} `json:"metadata,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
```

## File: ./services/payment-gateway-go/internal/models/payout.go

```
package models
func (p *Payout) IsTerminal() bool {return p.Status=="completed"||p.Status=="failed"||p.Status=="cancelled"}
func (p *Payout) CanTransitionTo(newStatus string) bool {
    transitions:=map[string][]string{"pending":{"processing","failed","cancelled"},"processing":{"completed","failed","cancelled"},"completed":{}, "failed":{"pending"}, "cancelled":{}}
    allowed,ok:=transitions[p.Status]
    if !ok{return false}
    for _,s:=range allowed{if s==newStatus{return true}}
    return false
}
```

