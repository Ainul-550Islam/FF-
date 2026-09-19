package cache

import (
    "context"
    "crypto/aes"
    "crypto/cipher"
    "crypto/rand"
    "encoding/base64"
    "fmt"
    "io"
    "sync"
    "time"
)

type TokenCache interface {
    Get(ctx context.Context, key string) (string, bool)
    Set(ctx context.Context, key, token string, ttl time.Duration) error
    Delete(ctx context.Context, key string) error
}

type InMemoryTokenCache struct {
    mu     sync.RWMutex
    tokens map[string]cachedToken
    encKey []byte
}

type cachedToken struct {
    token     string
    encrypted string
    expiresAt time.Time
}

func NewInMemoryTokenCache(encKey []byte) *InMemoryTokenCache {
    if len(encKey) == 0 {
        encKey = make([]byte, 32)
        rand.Read(encKey)
    }
    return &InMemoryTokenCache{tokens: make(map[string]cachedToken), encKey: encKey}
}

func (c *InMemoryTokenCache) Get(ctx context.Context, key string) (string, bool) {
    c.mu.RLock()
    defer c.mu.RUnlock()
    ct, exists := c.tokens[key]
    if !exists {
        return "", false
    }
    if time.Now().After(ct.expiresAt) {
        return "", false
    }
    if ct.encrypted != "" {
        decrypted, err := c.decrypt(ct.encrypted)
        if err != nil {
            return "", false
        }
        return decrypted, true
    }
    return ct.token, true
}

func (c *InMemoryTokenCache) Set(ctx context.Context, key, token string, ttl time.Duration) error {
    c.mu.Lock()
    defer c.mu.Unlock()
    encrypted, err := c.encrypt(token)
    if err != nil {
        return err
    }
    c.tokens[key] = cachedToken{encrypted: encrypted, expiresAt: time.Now().Add(ttl)}
    return nil
}

func (c *InMemoryTokenCache) Delete(ctx context.Context, key string) error {
    c.mu.Lock()
    defer c.mu.Unlock()
    delete(c.tokens, key)
    return nil
}

func (c *InMemoryTokenCache) encrypt(plaintext string) (string, error) {
    if len(c.encKey) != 32 {
        return "", fmt.Errorf("key must be 32 bytes")
    }
    block, err := aes.NewCipher(c.encKey)
    if err != nil {
        return "", err
    }
    gcm, err := cipher.NewGCM(block)
    if err != nil {
        return "", err
    }
    nonce := make([]byte, gcm.NonceSize())
    if _, err := io.ReadFull(rand.Reader, nonce); err != nil {
        return "", err
    }
    ciphertext := gcm.Seal(nonce, nonce, []byte(plaintext), nil)
    return base64.StdEncoding.EncodeToString(ciphertext), nil
}

func (c *InMemoryTokenCache) decrypt(encrypted string) (string, error) {
    data, err := base64.StdEncoding.DecodeString(encrypted)
    if err != nil {
        return "", err
    }
    block, err := aes.NewCipher(c.encKey)
    if err != nil {
        return "", err
    }
    gcm, err := cipher.NewGCM(block)
    if err != nil {
        return "", err
    }
    nonceSize := gcm.NonceSize()
    if len(data) < nonceSize {
        return "", fmt.Errorf("ciphertext too short")
    }
    nonce, ciphertext := data[:nonceSize], data[nonceSize:]
    plaintext, err := gcm.Open(nil, nonce, ciphertext, nil)
    if err != nil {
        return "", err
    }
    return string(plaintext), nil
}

func (c *InMemoryTokenCache) StartCleanup(ctx context.Context, interval time.Duration) {
    go func() {
        ticker := time.NewTicker(interval)
        defer ticker.Stop()
        for {
            select {
            case <-ctx.Done():
                return
            case <-ticker.C:
                c.cleanup()
            }
        }
    }()
}

func (c *InMemoryTokenCache) cleanup() {
    c.mu.Lock()
    defer c.mu.Unlock()
    now := time.Now()
    for key, ct := range c.tokens {
        if now.After(ct.expiresAt) {
            delete(c.tokens, key)
        }
    }
}

type RedisTokenCache struct {
    inMemory *InMemoryTokenCache
}

func NewRedisTokenCache(encKey []byte) *RedisTokenCache {
    return &RedisTokenCache{inMemory: NewInMemoryTokenCache(encKey)}
}

func (r *RedisTokenCache) Get(ctx context.Context, key string) (string, bool) {
    if token, ok := r.inMemory.Get(ctx, key); ok {
        return token, true
    }
    return "", false
}

func (r *RedisTokenCache) Set(ctx context.Context, key, token string, ttl time.Duration) error {
    return r.inMemory.Set(ctx, key, token, ttl)
}

func (r *RedisTokenCache) Delete(ctx context.Context, key string) error {
    r.inMemory.Delete(ctx, key)
    return nil
}
