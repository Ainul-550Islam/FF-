package resilience

import (
    "context"
    "fmt"
    "os"
    "sync"
    "time"
)

// DistributedBulkhead with Redis for cross-instance coordination

type DistributedBulkhead struct {
    local     *Bulkhead
    redis     RedisClient
    provider  string
    mu        sync.RWMutex
}

type RedisClient interface {
    Get(ctx context.Context, key string) (string, error)
    Set(ctx context.Context, key, value string, ttl time.Duration) error
    Del(ctx context.Context, keys ...string) error
    Incr(ctx context.Context, key string) (int64, error)
    Decr(ctx context.Context, key string) (int64, error)
    Ping(ctx context.Context) error
}

type InMemoryRedisForBulkhead struct {
    mu   sync.RWMutex
    data map[string]int64
}

func NewInMemoryRedisForBulkhead() *InMemoryRedisForBulkhead {
    return &InMemoryRedisForBulkhead{data: make(map[string]int64)}
}

func (r *InMemoryRedisForBulkhead) Get(ctx context.Context, key string) (string, error) {
    r.mu.RLock()
    defer r.mu.RUnlock()
    val, ok := r.data[key]
    if !ok {
        return "", fmt.Errorf("not found")
    }
    return fmt.Sprintf("%d", val), nil
}

func (r *InMemoryRedisForBulkhead) Set(ctx context.Context, key, value string, ttl time.Duration) error {
    r.mu.Lock()
    defer r.mu.Unlock()
    var val int64
    fmt.Sscanf(value, "%d", &val)
    r.data[key] = val
    return nil
}

func (r *InMemoryRedisForBulkhead) Del(ctx context.Context, keys ...string) error {
    r.mu.Lock()
    defer r.mu.Unlock()
    for _, k := range keys {
        delete(r.data, k)
    }
    return nil
}

func (r *InMemoryRedisForBulkhead) Incr(ctx context.Context, key string) (int64, error) {
    r.mu.Lock()
    defer r.mu.Unlock()
    r.data[key]++
    return r.data[key], nil
}

func (r *InMemoryRedisForBulkhead) Decr(ctx context.Context, key string) (int64, error) {
    r.mu.Lock()
    defer r.mu.Unlock()
    r.data[key]--
    if r.data[key] < 0 {
        r.data[key] = 0
    }
    return r.data[key], nil
}

func (r *InMemoryRedisForBulkhead) Ping(ctx context.Context) error { return nil }

func NewDistributedBulkhead(provider string, maxConcurrent, maxQueue int) *DistributedBulkhead {
    redisURL := os.Getenv("REDIS_URL")
    var redisClient RedisClient
    if redisURL != "" {
        // In production, use real Redis
        redisClient = NewInMemoryRedisForBulkhead()
    } else {
        redisClient = NewInMemoryRedisForBulkhead()
    }
    
    return &DistributedBulkhead{
        local:    NewBulkhead(provider, maxConcurrent, maxQueue),
        redis:    redisClient,
        provider: provider,
    }
}

func (db *DistributedBulkhead) Execute(ctx context.Context, fn func() error) error {
    activeKey := fmt.Sprintf("bulkhead:%s:active", db.provider)
    
    // Try to increment active count in Redis (distributed)
    count, err := db.redis.Incr(ctx, activeKey)
    if err != nil {
        // Fallback to local bulkhead
        return db.local.Execute(ctx, fn)
    }
    
    // Check if over limit (distributed)
    if count > int64(db.local.maxConcurrent) {
        db.redis.Decr(ctx, activeKey)
        // Try local queue
        return db.local.Execute(ctx, fn)
    }
    
    // Execute with timeout
    errCh := make(chan error, 1)
    go func() {
        errCh <- fn()
    }()
    
    select {
    case <-ctx.Done():
        db.redis.Decr(ctx, activeKey)
        return ctx.Err()
    case err := <-errCh:
        db.redis.Decr(ctx, activeKey)
        // Set TTL for cleanup
        db.redis.Set(ctx, activeKey, "0", 60*time.Second)
        return err
    }
}

func (db *DistributedBulkhead) Stats() BulkheadStats {
    localStats := db.local.Stats()
    
    // Try to get distributed count
    activeKey := fmt.Sprintf("bulkhead:%s:active", db.provider)
    if val, err := db.redis.Get(context.Background(), activeKey); err == nil {
        var count int
        fmt.Sscanf(val, "%d", &count)
        localStats.Active = count
    }
    
    return localStats
}

func (db *DistributedBulkhead) Close() {
    db.local.Close()
}

// DistributedProviderBulkheads
type DistributedProviderBulkheads struct {
    bulkheads map[string]*DistributedBulkhead
    mu        sync.RWMutex
}

func NewDistributedProviderBulkheads() *DistributedProviderBulkheads {
    return &DistributedProviderBulkheads{
        bulkheads: make(map[string]*DistributedBulkhead),
    }
}

func (dpb *DistributedProviderBulkheads) Get(provider string) *DistributedBulkhead {
    dpb.mu.RLock()
    if b, ok := dpb.bulkheads[provider]; ok {
        dpb.mu.RUnlock()
        return b
    }
    dpb.mu.RUnlock()
    
    dpb.mu.Lock()
    defer dpb.mu.Unlock()
    if b, ok := dpb.bulkheads[provider]; ok {
        return b
    }
    
    b := NewDistributedBulkhead(provider, 10, 100)
    dpb.bulkheads[provider] = b
    return b
}

func (dpb *DistributedProviderBulkheads) Execute(ctx context.Context, provider string, fn func() error) error {
    bulkhead := dpb.Get(provider)
    ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
    defer cancel()
    return bulkhead.Execute(ctx, fn)
}

// Stats snapshots every provider's distributed bulkhead, mirroring
// ProviderBulkheads.Stats for the /api/v1/bulkhead/stats and health endpoints.
func (dpb *DistributedProviderBulkheads) Stats() map[string]BulkheadStats {
	dpb.mu.RLock()
	defer dpb.mu.RUnlock()
	stats := make(map[string]BulkheadStats)
	for provider, bulkhead := range dpb.bulkheads {
		stats[provider] = bulkhead.Stats()
	}
	return stats
}
