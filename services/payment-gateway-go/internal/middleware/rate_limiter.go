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
