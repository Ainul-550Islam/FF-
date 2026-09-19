package storage

import (
    "context"
    "crypto/sha256"
    "fmt"
    "sync"
)

type ShardedMemoryStore struct {
    shards    []*MemoryStoreShard
    numShards int
}

type MemoryStoreShard struct {
    mu       sync.RWMutex
    payments map[string]*Payment
    wallets  map[string]*Wallet
    ledger   map[string]*LedgerEntry
    events   map[string]*WebhookEvent
}

func NewShardedMemoryStore(numShards int) *ShardedMemoryStore {
    if numShards <= 0 {
        numShards = 16
    }
    shards := make([]*MemoryStoreShard, numShards)
    for i := 0; i < numShards; i++ {
        shards[i] = &MemoryStoreShard{
            payments: make(map[string]*Payment),
            wallets:  make(map[string]*Wallet),
            ledger:   make(map[string]*LedgerEntry),
            events:   make(map[string]*WebhookEvent),
        }
    }
    return &ShardedMemoryStore{shards: shards, numShards: numShards}
}

func (s *ShardedMemoryStore) getShard(key string) *MemoryStoreShard {
    hash := sha256.Sum256([]byte(key))
    shardIndex := int(hash[0])<<24 | int(hash[1])<<16 | int(hash[2])<<8 | int(hash[3])
    if shardIndex < 0 {
        shardIndex = -shardIndex
    }
    return s.shards[shardIndex%s.numShards]
}

func (s *ShardedMemoryStore) getShardByID(id string) *MemoryStoreShard {
    return s.getShard(id)
}

func (s *ShardedMemoryStore) CreatePayment(ctx context.Context, p *Payment) error {
    shard := s.getShardByID(p.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    if _, exists := shard.payments[p.ID]; exists {
        return fmt.Errorf("payment already exists: %s", p.ID)
    }
    shard.payments[p.ID] = p
    return nil
}

func (s *ShardedMemoryStore) GetPayment(ctx context.Context, id string) (*Payment, error) {
    shard := s.getShardByID(id)
    shard.mu.RLock()
    defer shard.mu.RUnlock()
    p, exists := shard.payments[id]
    if !exists {
        return nil, fmt.Errorf("payment not found: %s", id)
    }
    return p, nil
}

func (s *ShardedMemoryStore) UpdatePayment(ctx context.Context, p *Payment) error {
    shard := s.getShardByID(p.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    shard.payments[p.ID] = p
    return nil
}

func (s *ShardedMemoryStore) ListPaymentsByUser(ctx context.Context, userID string, limit, offset int) ([]*Payment, error) {
    var all []*Payment
    for _, shard := range s.shards {
        shard.mu.RLock()
        for _, p := range shard.payments {
            if p.UserID == userID {
                all = append(all, p)
            }
        }
        shard.mu.RUnlock()
    }
    if offset >= len(all) {
        return []*Payment{}, nil
    }
    end := offset + limit
    if end > len(all) {
        end = len(all)
    }
    if limit <= 0 {
        return all[offset:], nil
    }
    return all[offset:end], nil
}

func (s *ShardedMemoryStore) CreateWallet(ctx context.Context, w *Wallet) error {
    shard := s.getShardByID(w.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    shard.wallets[w.ID] = w
    return nil
}

func (s *ShardedMemoryStore) GetWallet(ctx context.Context, id string) (*Wallet, error) {
    shard := s.getShardByID(id)
    shard.mu.RLock()
    defer shard.mu.RUnlock()
    w, exists := shard.wallets[id]
    if !exists {
        return nil, fmt.Errorf("wallet not found: %s", id)
    }
    return w, nil
}

func (s *ShardedMemoryStore) UpdateWallet(ctx context.Context, w *Wallet) error {
    shard := s.getShardByID(w.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    shard.wallets[w.ID] = w
    return nil
}

func (s *ShardedMemoryStore) CreateLedgerEntry(ctx context.Context, e *LedgerEntry) error {
    shard := s.getShardByID(e.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    shard.ledger[e.ID] = e
    return nil
}

func (s *ShardedMemoryStore) ListLedgerByWallet(ctx context.Context, walletID string, limit, offset int) ([]*LedgerEntry, error) {
    var all []*LedgerEntry
    for _, shard := range s.shards {
        shard.mu.RLock()
        for _, e := range shard.ledger {
            if e.WalletID == walletID {
                all = append(all, e)
            }
        }
        shard.mu.RUnlock()
    }
    if offset >= len(all) {
        return []*LedgerEntry{}, nil
    }
    end := offset + limit
    if end > len(all) {
        end = len(all)
    }
    if limit <= 0 {
        return all[offset:], nil
    }
    return all[offset:end], nil
}

func (s *ShardedMemoryStore) CreateWebhookEvent(ctx context.Context, e *WebhookEvent) error {
    shard := s.getShardByID(e.ID)
    shard.mu.Lock()
    defer shard.mu.Unlock()
    shard.events[e.ID] = e
    return nil
}

func (s *ShardedMemoryStore) GetWebhookEventByProviderRef(ctx context.Context, provider, externalRef string) (*WebhookEvent, error) {
    for _, shard := range s.shards {
        shard.mu.RLock()
        for _, e := range shard.events {
            if e.Provider == provider && e.ExternalRef == externalRef {
                shard.mu.RUnlock()
                return e, nil
            }
        }
        shard.mu.RUnlock()
    }
    return nil, fmt.Errorf("webhook event not found")
}

func (s *ShardedMemoryStore) Migrate(ctx context.Context) error { return nil }
func (s *ShardedMemoryStore) Close() error { return nil }
func (s *ShardedMemoryStore) HealthCheck(ctx context.Context) error { return nil }
