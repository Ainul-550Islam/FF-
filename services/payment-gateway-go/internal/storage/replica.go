package storage

import (
    "context"
    "database/sql"
    "fmt"
    "log"
)

type ReplicaStore struct {
    primary    Store
    replica    Store
    useReplica bool
}

func NewReplicaStore(primary, replica Store, useReplica bool) *ReplicaStore {
    return &ReplicaStore{primary: primary, replica: replica, useReplica: useReplica}
}

func (r *ReplicaStore) getReadStore() Store {
    if r.useReplica && r.replica != nil {
        return r.replica
    }
    return r.primary
}

func (r *ReplicaStore) getWriteStore() Store { return r.primary }

func (r *ReplicaStore) CreatePayment(ctx context.Context, p *Payment) error {
    return r.getWriteStore().CreatePayment(ctx, p)
}

func (r *ReplicaStore) GetPayment(ctx context.Context, id string) (*Payment, error) {
    store := r.getReadStore()
    payment, err := store.GetPayment(ctx, id)
    if err != nil && store != r.primary {
        return r.primary.GetPayment(ctx, id)
    }
    return payment, err
}

func (r *ReplicaStore) UpdatePayment(ctx context.Context, p *Payment) error {
    return r.getWriteStore().UpdatePayment(ctx, p)
}

func (r *ReplicaStore) ListPaymentsByUser(ctx context.Context, userID string, limit, offset int) ([]*Payment, error) {
    store := r.getReadStore()
    payments, err := store.ListPaymentsByUser(ctx, userID, limit, offset)
    if err != nil && store != r.primary {
        return r.primary.ListPaymentsByUser(ctx, userID, limit, offset)
    }
    return payments, err
}

func (r *ReplicaStore) CreateWallet(ctx context.Context, w *Wallet) error {
    return r.getWriteStore().CreateWallet(ctx, w)
}

func (r *ReplicaStore) GetWallet(ctx context.Context, id string) (*Wallet, error) {
    store := r.getReadStore()
    wallet, err := store.GetWallet(ctx, id)
    if err != nil && store != r.primary {
        return r.primary.GetWallet(ctx, id)
    }
    return wallet, err
}

func (r *ReplicaStore) UpdateWallet(ctx context.Context, w *Wallet) error {
    return r.getWriteStore().UpdateWallet(ctx, w)
}

func (r *ReplicaStore) CreateLedgerEntry(ctx context.Context, e *LedgerEntry) error {
    return r.getWriteStore().CreateLedgerEntry(ctx, e)
}

func (r *ReplicaStore) ListLedgerByWallet(ctx context.Context, walletID string, limit, offset int) ([]*LedgerEntry, error) {
    store := r.getReadStore()
    entries, err := store.ListLedgerByWallet(ctx, walletID, limit, offset)
    if err != nil && store != r.primary {
        return r.primary.ListLedgerByWallet(ctx, walletID, limit, offset)
    }
    return entries, err
}

func (r *ReplicaStore) CreateWebhookEvent(ctx context.Context, e *WebhookEvent) error {
    return r.getWriteStore().CreateWebhookEvent(ctx, e)
}

func (r *ReplicaStore) GetWebhookEventByProviderRef(ctx context.Context, provider, externalRef string) (*WebhookEvent, error) {
    store := r.getReadStore()
    event, err := store.GetWebhookEventByProviderRef(ctx, provider, externalRef)
    if err != nil && store != r.primary {
        return r.primary.GetWebhookEventByProviderRef(ctx, provider, externalRef)
    }
    return event, err
}

func (r *ReplicaStore) Migrate(ctx context.Context) error { return r.primary.Migrate(ctx) }

func (r *ReplicaStore) Close() error {
    if err := r.primary.Close(); err != nil {
        log.Printf("Error closing primary: %v", err)
    }
    if r.replica != nil {
        if err := r.replica.Close(); err != nil {
            log.Printf("Error closing replica: %v", err)
        }
    }
    return nil
}

func (r *ReplicaStore) HealthCheck(ctx context.Context) error {
    if err := r.primary.HealthCheck(ctx); err != nil {
        return fmt.Errorf("primary health check failed: %w", err)
    }
    if r.replica != nil && r.useReplica {
        if err := r.replica.HealthCheck(ctx); err != nil {
            log.Printf("Replica health check failed, falling back to primary: %v", err)
        }
    }
    return nil
}

func OpenReplicaDatabase(primaryDB *sql.DB, replicaURL, driver string) (Store, error) {
    if replicaURL == "" {
        return nil, nil
    }
    return nil, nil
}
