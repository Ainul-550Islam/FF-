package redis
import ("context"; "encoding/json"; "fmt"; "time")
type RedisIdempotencyStore struct{client *RealRedisClient; prefix string; ttl time.Duration}
func NewRedisIdempotencyStore(client *RealRedisClient) *RedisIdempotencyStore {return &RedisIdempotencyStore{client:client,prefix:"ffarena:idempotency:",ttl:3600*time.Second}}
func (s *RedisIdempotencyStore) Key(key string) string {return s.prefix+key}
func (s *RedisIdempotencyStore) Get(ctx context.Context, key string) (map[string]interface{}, error){
    fullKey:=s.Key(key)
    data,err:=s.client.Get(ctx,fullKey)
    if err!=nil{return nil, err}
    var result map[string]interface{}
    if err:=json.Unmarshal([]byte(data),&result); err!=nil{return nil, err}
    return result, nil
}
func (s *RedisIdempotencyStore) Set(ctx context.Context, key string, value map[string]interface{}) error{
    fullKey:=s.Key(key)
    b,err:=json.Marshal(value)
    if err!=nil{return err}
    return s.client.Set(ctx,fullKey,string(b),s.ttl)
}
func (s *RedisIdempotencyStore) Delete(ctx context.Context, key string) error{return s.client.Del(ctx,s.Key(key))}
func (s *RedisIdempotencyStore) Exists(ctx context.Context, key string) (bool, error){_,err:=s.client.Get(ctx,s.Key(key)); if err!=nil{return false, nil}; return true, nil}
func (s *RedisIdempotencyStore) TTL() time.Duration {return 3600*time.Second}
func (s *RedisIdempotencyStore) Prefix() string {return "ffarena:idempotency:"}
func GenerateIdempotencyKey() string {return fmt.Sprintf("idemp-%d",time.Now().UnixNano())}
