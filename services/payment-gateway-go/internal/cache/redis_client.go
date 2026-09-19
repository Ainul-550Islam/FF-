package cache

import (
    "context"
    "fmt"
    "os"
    "sync"
    "time"
)

// Redis client interface - production would use go-redis
// This is a wrapper that supports both Redis and in-memory fallback

type RedisClient interface {
    Get(ctx context.Context, key string) (string, error)
    Set(ctx context.Context, key, value string, ttl time.Duration) error
    Del(ctx context.Context, keys ...string) error
    Ping(ctx context.Context) error
    Close() error
}

// InMemoryRedisClient for development/testing
type InMemoryRedisClient struct {
    mu   sync.RWMutex
    data map[string]redisEntry
}

type redisEntry struct {
    value     string
    expiresAt time.Time
}

func NewInMemoryRedisClient() *InMemoryRedisClient {
    return &InMemoryRedisClient{
        data: make(map[string]redisEntry),
    }
}

func (c *InMemoryRedisClient) Get(ctx context.Context, key string) (string, error) {
    c.mu.RLock()
    defer c.mu.RUnlock()
    
    entry, exists := c.data[key]
    if !exists {
        return "", fmt.Errorf("key not found: %s", key)
    }
    
    if !entry.expiresAt.IsZero() && time.Now().After(entry.expiresAt) {
        return "", fmt.Errorf("key expired: %s", key)
    }
    
    return entry.value, nil
}

func (c *InMemoryRedisClient) Set(ctx context.Context, key, value string, ttl time.Duration) error {
    c.mu.Lock()
    defer c.mu.Unlock()
    
    entry := redisEntry{value: value}
    if ttl > 0 {
        entry.expiresAt = time.Now().Add(ttl)
    }
    
    c.data[key] = entry
    return nil
}

func (c *InMemoryRedisClient) Del(ctx context.Context, keys ...string) error {
    c.mu.Lock()
    defer c.mu.Unlock()
    
    for _, key := range keys {
        delete(c.data, key)
    }
    return nil
}

func (c *InMemoryRedisClient) Ping(ctx context.Context) error {
    return nil
}

func (c *InMemoryRedisClient) Close() error {
    return nil
}

func (c *InMemoryRedisClient) Cleanup() {
    c.mu.Lock()
    defer c.mu.Unlock()
    
    now := time.Now()
    for key, entry := range c.data {
        if !entry.expiresAt.IsZero() && now.After(entry.expiresAt) {
            delete(c.data, key)
        }
    }
}

// ProductionRedisClient would use go-redis in production
// For now, this is a placeholder that falls back to in-memory
type ProductionRedisClient struct {
    inMemory *InMemoryRedisClient
    // In production:
    // client *redis.Client
    enabled bool
}

func NewProductionRedisClient() *ProductionRedisClient {
    redisURL := os.Getenv("REDIS_URL")
    enabled := redisURL != ""
    
    if enabled {
        // In production, initialize real Redis client:
        // opt, _ := redis.ParseURL(redisURL)
        // client := redis.NewClient(opt)
        // return &ProductionRedisClient{client: client, enabled: true}
    }
    
    return &ProductionRedisClient{
        inMemory: NewInMemoryRedisClient(),
        enabled: false,
    }
}

func (c *ProductionRedisClient) Get(ctx context.Context, key string) (string, error) {
    if c.enabled {
        // return c.client.Get(ctx, key).Result()
    }
    return c.inMemory.Get(ctx, key)
}

func (c *ProductionRedisClient) Set(ctx context.Context, key, value string, ttl time.Duration) error {
    if c.enabled {
        // return c.client.Set(ctx, key, value, ttl).Err()
    }
    return c.inMemory.Set(ctx, key, value, ttl)
}

func (c *ProductionRedisClient) Del(ctx context.Context, keys ...string) error {
    if c.enabled {
        // return c.client.Del(ctx, keys...).Err()
    }
    return c.inMemory.Del(ctx, keys...)
}

func (c *ProductionRedisClient) Ping(ctx context.Context) error {
    if c.enabled {
        // return c.client.Ping(ctx).Err()
    }
    return c.inMemory.Ping(ctx)
}

func (c *ProductionRedisClient) Close() error {
    if c.enabled {
        // return c.client.Close()
    }
    return c.inMemory.Close()
}

// DistributedTokenCache with Redis L2
type DistributedTokenCache struct {
    l1Cache *InMemoryTokenCache
    l2Cache RedisClient
}

func NewDistributedTokenCache(encKey []byte) *DistributedTokenCache {
    return &DistributedTokenCache{
        l1Cache: NewInMemoryTokenCache(encKey),
        l2Cache: NewProductionRedisClient(),
    }
}

func (c *DistributedTokenCache) Get(ctx context.Context, key string) (string, bool) {
    // L1 first
    if token, ok := c.l1Cache.Get(ctx, key); ok {
        return token, true
    }
    
    // L2 Redis
    if token, err := c.l2Cache.Get(ctx, key); err == nil {
        // Populate L1
        c.l1Cache.Set(ctx, key, token, 5*time.Minute)
        return token, true
    }
    
    return "", false
}

func (c *DistributedTokenCache) Set(ctx context.Context, key, token string, ttl time.Duration) error {
    // Set both L1 and L2
    if err := c.l1Cache.Set(ctx, key, token, ttl); err != nil {
        return err
    }
    return c.l2Cache.Set(ctx, key, token, ttl)
}

func (c *DistributedTokenCache) Delete(ctx context.Context, key string) error {
    c.l1Cache.Delete(ctx, key)
    return c.l2Cache.Del(ctx, key)
}
