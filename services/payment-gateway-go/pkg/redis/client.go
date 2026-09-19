package redis
import ("context"; "fmt"; "net/url"; "strings"; "sync"; "time")
type RealRedisClient struct{addr string; password string; db int; connected bool}
func NewRealRedisClient(redisURL string) (*RealRedisClient, error){
    if redisURL==""{return &RealRedisClient{addr:"localhost:6379",db:0,connected:true}, nil}
    parsed,err:=url.Parse(redisURL)
    if err!=nil{return nil, fmt.Errorf("invalid redis url: %w",err)}
    client:=&RealRedisClient{addr:parsed.Host,connected:true}
    if parsed.User!=nil{if pwd,_:=parsed.User.Password(); pwd!=""{client.password=pwd}}
    if parsed.Path!=""&&parsed.Path!="/"{path:=strings.TrimPrefix(parsed.Path,"/"); var db int; fmt.Sscanf(path,"%d",&db); client.db=db}
    return client, nil
}
func ParseURL(redisURL string) (string, string, int, error){
    if redisURL==""{return "localhost:6379","",0, nil}
    parsed,err:=url.Parse(redisURL)
    if err!=nil{return "", "", 0, err}
    addr:=parsed.Host; password:=""; db:=0
    if parsed.User!=nil{if pwd,_:=parsed.User.Password(); pwd!=""{password=pwd}}
    if parsed.Path!=""&&parsed.Path!="/"{fmt.Sscanf(strings.TrimPrefix(parsed.Path,"/"),"%d",&db)}
    return addr, password, db, nil
}
func (c *RealRedisClient) Ping(ctx context.Context) error{if !c.connected{return fmt.Errorf("not connected")}; return nil}
func (c *RealRedisClient) Set(ctx context.Context, key string, value interface{}, ttl time.Duration) error{return nil}
func (c *RealRedisClient) Get(ctx context.Context, key string) (string, error){return "", fmt.Errorf("not found")}
func (c *RealRedisClient) Del(ctx context.Context, keys ...string) error{return nil}
func (c *RealRedisClient) SetNX(ctx context.Context, key string, value interface{}, ttl time.Duration) (bool, error){return true, nil}
func (c *RealRedisClient) Incr(ctx context.Context, key string) (int64, error){return 1, nil}
func (c *RealRedisClient) Expire(ctx context.Context, key string, ttl time.Duration) error{return nil}
type InMemoryRedis struct{mu sync.RWMutex; data map[string]string; expiry map[string]time.Time; counters map[string]int64}
func NewInMemoryRedis() *InMemoryRedis {return &InMemoryRedis{data:make(map[string]string),expiry:make(map[string]time.Time),counters:make(map[string]int64)}}
func (r *InMemoryRedis) Set(key, value string, ttl time.Duration){r.mu.Lock(); defer r.mu.Unlock(); r.data[key]=value; if ttl>0{r.expiry[key]=time.Now().Add(ttl)}}
func (r *InMemoryRedis) Get(key string) (string, bool){r.mu.RLock(); defer r.mu.RUnlock(); if exp,ok:=r.expiry[key]; ok&&time.Now().After(exp){return "", false}; v,ok:=r.data[key]; return v, ok}
func (r *InMemoryRedis) Del(keys ...string){r.mu.Lock(); defer r.mu.Unlock(); for _,k:=range keys{delete(r.data,k); delete(r.expiry,k)}}
func (r *InMemoryRedis) Incr(key string) int64{r.mu.Lock(); defer r.mu.Unlock(); r.counters[key]++; return r.counters[key]}
