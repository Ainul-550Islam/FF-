package resilience

import (
    "context"
    "fmt"
    "sync"
    "time"
)

type Bulkhead struct {
    name          string
    maxConcurrent int
    maxQueue      int
    sem           chan struct{}
    queue         chan func()
    wg            sync.WaitGroup
    mu            sync.RWMutex
    active        int
    queued        int
    rejected      int64
}

func NewBulkhead(name string, maxConcurrent, maxQueue int) *Bulkhead {
    if maxConcurrent <= 0 {
        maxConcurrent = 10
    }
    if maxQueue <= 0 {
        maxQueue = 100
    }
    b := &Bulkhead{
        name:          name,
        maxConcurrent: maxConcurrent,
        maxQueue:      maxQueue,
        sem:           make(chan struct{}, maxConcurrent),
        queue:         make(chan func(), maxQueue),
    }
    for i := 0; i < maxConcurrent; i++ {
        b.wg.Add(1)
        go b.worker()
    }
    return b
}

func (b *Bulkhead) worker() {
    defer b.wg.Done()
    for fn := range b.queue {
        b.mu.Lock()
        b.queued--
        b.active++
        b.mu.Unlock()
        b.sem <- struct{}{}
        fn()
        <-b.sem
        b.mu.Lock()
        b.active--
        b.mu.Unlock()
    }
}

func (b *Bulkhead) Execute(ctx context.Context, fn func() error) error {
    resultCh := make(chan error, 1)
    task := func() { resultCh <- fn() }
    select {
    case b.queue <- task:
        b.mu.Lock()
        b.queued++
        b.mu.Unlock()
        select {
        case <-ctx.Done():
            return ctx.Err()
        case err := <-resultCh:
            return err
        }
    default:
        b.mu.Lock()
        b.rejected++
        b.mu.Unlock()
        return fmt.Errorf("bulkhead %s at capacity", b.name)
    }
}

func (b *Bulkhead) Stats() BulkheadStats {
    b.mu.RLock()
    defer b.mu.RUnlock()
    return BulkheadStats{Name: b.name, Active: b.active, Queued: b.queued, MaxConcurrent: b.maxConcurrent, MaxQueue: b.maxQueue, Rejected: b.rejected}
}

type BulkheadStats struct {
    Name          string `json:"name"`
    Active        int    `json:"active"`
    Queued        int    `json:"queued"`
    MaxConcurrent int    `json:"max_concurrent"`
    MaxQueue      int    `json:"max_queue"`
    Rejected      int64  `json:"rejected"`
}

func (b *Bulkhead) Close() { close(b.queue); b.wg.Wait() }

type ProviderBulkheads struct {
    bulkheads map[string]*Bulkhead
    mu        sync.RWMutex
}

func NewProviderBulkheads() *ProviderBulkheads {
    return &ProviderBulkheads{bulkheads: make(map[string]*Bulkhead)}
}

func (pb *ProviderBulkheads) Get(provider string) *Bulkhead {
    pb.mu.RLock()
    if b, ok := pb.bulkheads[provider]; ok {
        pb.mu.RUnlock()
        return b
    }
    pb.mu.RUnlock()
    pb.mu.Lock()
    defer pb.mu.Unlock()
    if b, ok := pb.bulkheads[provider]; ok {
        return b
    }
    b := NewBulkhead(provider, 10, 100)
    pb.bulkheads[provider] = b
    return b
}

func (pb *ProviderBulkheads) Execute(ctx context.Context, provider string, fn func() error) error {
    bulkhead := pb.Get(provider)
    ctx, cancel := context.WithTimeout(ctx, 30*time.Second)
    defer cancel()
    return bulkhead.Execute(ctx, fn)
}

func (pb *ProviderBulkheads) Stats() map[string]BulkheadStats {
    pb.mu.RLock()
    defer pb.mu.RUnlock()
    stats := make(map[string]BulkheadStats)
    for provider, bulkhead := range pb.bulkheads {
        stats[provider] = bulkhead.Stats()
    }
    return stats
}
