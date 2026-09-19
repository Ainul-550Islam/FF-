package redis
import ("context"; "fmt"; "time")
type RedisRateLimiter struct{client *RealRedisClient; prefix string; limit int; window time.Duration}
func NewRedisRateLimiter(client *RealRedisClient, limit int, window time.Duration) *RedisRateLimiter {return &RedisRateLimiter{client:client,prefix:"ffarena:ratelimit:",limit:limit,window:window}}
func (r *RedisRateLimiter) Key(identifier string) string {return r.prefix+identifier}
func (r *RedisRateLimiter) Allow(ctx context.Context, identifier string) (bool, error){
    key:=r.Key(identifier)
    count,err:=r.client.Incr(ctx,key)
    if err!=nil{return false, err}
    if count==1{if err:=r.client.Expire(ctx,key,r.window); err!=nil{return false, err}}
    if count>int64(r.limit){return false, nil}
    return true, nil
}
func (r *RedisRateLimiter) Remaining(ctx context.Context, identifier string) (int, error){return r.limit, nil}
func (r *RedisRateLimiter) Reset(ctx context.Context, identifier string) error{return r.client.Del(ctx,r.Key(identifier))}
func (r *RedisRateLimiter) Limit() int {return r.limit}
func (r *RedisRateLimiter) Window() time.Duration {return r.window}
func (r *RedisRateLimiter) Prefix() string {return "ffarena:ratelimit:"}
func (r *RedisRateLimiter) AtomicIncr(ctx context.Context, key string) (int64, error){
    count,err:=r.client.Incr(ctx,key)
    if err!=nil{return 0, fmt.Errorf("atomic incr failed: %w",err)}
    return count, nil
}
