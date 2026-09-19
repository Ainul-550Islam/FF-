package redis
import ("context"; "time")
type RedisLock struct{client *RealRedisClient; prefix string; ttl time.Duration}
func NewRedisLock(client *RealRedisClient) *RedisLock {return &RedisLock{client:client,prefix:"ffarena:lock:",ttl:30*time.Second}}
func (l *RedisLock) LockKey(key string) string {return l.prefix+key}
func (l *RedisLock) Acquire(ctx context.Context, key string) (bool, error){return l.client.SetNX(ctx,l.LockKey(key),"locked",l.ttl)}
func (l *RedisLock) Release(ctx context.Context, key string) error{return l.client.Del(ctx,l.LockKey(key))}
func (l *RedisLock) Prefix() string {return "ffarena:lock:"}
