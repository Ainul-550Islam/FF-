package webhooks

import (
    "context"
    "encoding/json"
    "fmt"
    "sync"
    "time"
)

type DeadLetterEntry struct {
    ID          string                 `json:"id"`
    Provider    string                 `json:"provider"`
    EventID     string                 `json:"event_id"`
    Payload     map[string]interface{} `json:"payload"`
    RawBody     []byte                 `json:"-"`
    Error       string                 `json:"error"`
    Attempts    int                    `json:"attempts"`
    FirstFailed time.Time              `json:"first_failed"`
    LastFailed  time.Time              `json:"last_failed"`
    NextRetry   *time.Time             `json:"next_retry,omitempty"`
}

type DeadLetterQueue struct {
    mu      sync.RWMutex
    entries map[string]*DeadLetterEntry
    maxSize int
}

func NewDeadLetterQueue(maxSize int) *DeadLetterQueue {
    if maxSize <= 0 {
        maxSize = 1000
    }
    return &DeadLetterQueue{entries: make(map[string]*DeadLetterEntry), maxSize: maxSize}
}

func (dlq *DeadLetterQueue) Add(ctx context.Context, entry *DeadLetterEntry) error {
    dlq.mu.Lock()
    defer dlq.mu.Unlock()
    if len(dlq.entries) >= dlq.maxSize {
        var oldestKey string
        var oldestTime time.Time
        for k, v := range dlq.entries {
            if oldestKey == "" || v.FirstFailed.Before(oldestTime) {
                oldestKey = k
                oldestTime = v.FirstFailed
            }
        }
        if oldestKey != "" {
            delete(dlq.entries, oldestKey)
        }
    }
    if entry.ID == "" {
        entry.ID = fmt.Sprintf("dlq_%d", time.Now().UnixNano())
    }
    if entry.FirstFailed.IsZero() {
        entry.FirstFailed = time.Now()
    }
    entry.LastFailed = time.Now()
    dlq.entries[entry.ID] = entry
    return nil
}

func (dlq *DeadLetterQueue) Get(ctx context.Context, id string) (*DeadLetterEntry, error) {
    dlq.mu.RLock()
    defer dlq.mu.RUnlock()
    entry, exists := dlq.entries[id]
    if !exists {
        return nil, fmt.Errorf("dead letter not found: %s", id)
    }
    return entry, nil
}

func (dlq *DeadLetterQueue) List(ctx context.Context, limit, offset int) ([]*DeadLetterEntry, error) {
    dlq.mu.RLock()
    defer dlq.mu.RUnlock()
    var all []*DeadLetterEntry
    for _, entry := range dlq.entries {
        all = append(all, entry)
    }
    if offset >= len(all) {
        return []*DeadLetterEntry{}, nil
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

func (dlq *DeadLetterQueue) Remove(ctx context.Context, id string) error {
    dlq.mu.Lock()
    defer dlq.mu.Unlock()
    delete(dlq.entries, id)
    return nil
}

func (dlq *DeadLetterQueue) Retry(ctx context.Context, id string) (*DeadLetterEntry, error) {
    dlq.mu.Lock()
    defer dlq.mu.Unlock()
    entry, exists := dlq.entries[id]
    if !exists {
        return nil, fmt.Errorf("not found: %s", id)
    }
    entry.Attempts++
    entry.LastFailed = time.Now()
    backoff := time.Duration(1<<uint(entry.Attempts-1)) * time.Minute
    if backoff > 60*time.Minute {
        backoff = 60 * time.Minute
    }
    nextRetry := time.Now().Add(backoff)
    entry.NextRetry = &nextRetry
    return entry, nil
}

func (dlq *DeadLetterQueue) Stats() DeadLetterStats {
    dlq.mu.RLock()
    defer dlq.mu.RUnlock()
    stats := DeadLetterStats{Total: len(dlq.entries)}
    for _, entry := range dlq.entries {
        stats.TotalAttempts += entry.Attempts
        if entry.Provider != "" {
            if stats.ByProvider == nil {
                stats.ByProvider = make(map[string]int)
            }
            stats.ByProvider[entry.Provider]++
        }
    }
    return stats
}

type DeadLetterStats struct {
    Total         int            `json:"total"`
    TotalAttempts int            `json:"total_attempts"`
    ByProvider    map[string]int `json:"by_provider"`
}

func (e *DeadLetterEntry) ToJSON() ([]byte, error) {
    return json.Marshal(e)
}
