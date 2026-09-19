package webhooks

import (
    "context"
    "database/sql"
    "encoding/json"
    "fmt"
    "time"
)

// PersistentDeadLetterQueue with DB storage

type PersistentDeadLetterQueue struct {
    db      *sql.DB
    memory  *DeadLetterQueue
}

func NewPersistentDeadLetterQueue(db *sql.DB, maxMemory int) *PersistentDeadLetterQueue {
    return &PersistentDeadLetterQueue{
        db:     db,
        memory: NewDeadLetterQueue(maxMemory),
    }
}

func (p *PersistentDeadLetterQueue) Migrate(ctx context.Context) error {
    if p.db == nil {
        return nil
    }
    
    query := `
    CREATE TABLE IF NOT EXISTS webhook_dead_letters (
        id TEXT PRIMARY KEY,
        provider TEXT NOT NULL,
        event_id TEXT NOT NULL,
        payload JSONB,
        raw_body BYTEA,
        error TEXT,
        attempts INTEGER DEFAULT 0,
        first_failed TIMESTAMP NOT NULL,
        last_failed TIMESTAMP NOT NULL,
        next_retry TIMESTAMP,
        created_at TIMESTAMP DEFAULT NOW()
    );
    CREATE INDEX IF NOT EXISTS idx_dead_letters_provider ON webhook_dead_letters(provider);
    CREATE INDEX IF NOT EXISTS idx_dead_letters_next_retry ON webhook_dead_letters(next_retry) WHERE next_retry IS NOT NULL;
    `
    
    _, err := p.db.ExecContext(ctx, query)
    return err
}

func (p *PersistentDeadLetterQueue) Add(ctx context.Context, entry *DeadLetterEntry) error {
    // Add to memory first
    if err := p.memory.Add(ctx, entry); err != nil {
        return err
    }
    
    // Persist to DB if available
    if p.db == nil {
        return nil
    }
    
    payloadJSON, _ := json.Marshal(entry.Payload)
    
    query := `
    INSERT INTO webhook_dead_letters (id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry)
    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10)
    ON CONFLICT (id) DO UPDATE SET
        error = EXCLUDED.error,
        attempts = EXCLUDED.attempts,
        last_failed = EXCLUDED.last_failed,
        next_retry = EXCLUDED.next_retry
    `
    
    _, err := p.db.ExecContext(ctx, query,
        entry.ID, entry.Provider, entry.EventID, payloadJSON, entry.RawBody,
        entry.Error, entry.Attempts, entry.FirstFailed, entry.LastFailed, entry.NextRetry,
    )
    
    return err
}

func (p *PersistentDeadLetterQueue) Get(ctx context.Context, id string) (*DeadLetterEntry, error) {
    // Try memory first
    if entry, err := p.memory.Get(ctx, id); err == nil {
        return entry, nil
    }
    
    if p.db == nil {
        return nil, fmt.Errorf("not found: %s", id)
    }
    
    query := `SELECT id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry FROM webhook_dead_letters WHERE id = $1`
    row := p.db.QueryRowContext(ctx, query, id)
    
    var entry DeadLetterEntry
    var payloadJSON []byte
    err := row.Scan(&entry.ID, &entry.Provider, &entry.EventID, &payloadJSON, &entry.RawBody, &entry.Error, &entry.Attempts, &entry.FirstFailed, &entry.LastFailed, &entry.NextRetry)
    if err != nil {
        return nil, err
    }
    
    json.Unmarshal(payloadJSON, &entry.Payload)
    return &entry, nil
}

func (p *PersistentDeadLetterQueue) List(ctx context.Context, limit, offset int) ([]*DeadLetterEntry, error) {
    // For simplicity, use memory list - in production, query DB with pagination
    return p.memory.List(ctx, limit, offset)
}

func (p *PersistentDeadLetterQueue) Remove(ctx context.Context, id string) error {
    p.memory.Remove(ctx, id)
    
    if p.db == nil {
        return nil
    }
    
    _, err := p.db.ExecContext(ctx, `DELETE FROM webhook_dead_letters WHERE id = $1`, id)
    return err
}

func (p *PersistentDeadLetterQueue) Retry(ctx context.Context, id string) (*DeadLetterEntry, error) {
    entry, err := p.memory.Retry(ctx, id)
    if err != nil {
        return nil, err
    }
    
    if p.db != nil {
        _, err = p.db.ExecContext(ctx,
            `UPDATE webhook_dead_letters SET attempts = $1, last_failed = $2, next_retry = $3 WHERE id = $4`,
            entry.Attempts, entry.LastFailed, entry.NextRetry, id,
        )
    }
    
    return entry, err
}

func (p *PersistentDeadLetterQueue) Stats() DeadLetterStats {
    return p.memory.Stats()
}

func (p *PersistentDeadLetterQueue) ListDueForRetry(ctx context.Context, limit int) ([]*DeadLetterEntry, error) {
    if p.db == nil {
        return p.memory.List(ctx, limit, 0)
    }
    
    query := `SELECT id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry FROM webhook_dead_letters WHERE next_retry IS NOT NULL AND next_retry <= NOW() ORDER BY next_retry ASC LIMIT $1`
    rows, err := p.db.QueryContext(ctx, query, limit)
    if err != nil {
        return nil, err
    }
    defer rows.Close()
    
    var entries []*DeadLetterEntry
    for rows.Next() {
        var entry DeadLetterEntry
        var payloadJSON []byte
        err := rows.Scan(&entry.ID, &entry.Provider, &entry.EventID, &payloadJSON, &entry.RawBody, &entry.Error, &entry.Attempts, &entry.FirstFailed, &entry.LastFailed, &entry.NextRetry)
        if err != nil {
            continue
        }
        json.Unmarshal(payloadJSON, &entry.Payload)
        entries = append(entries, &entry)
    }
    
    return entries, nil
}
