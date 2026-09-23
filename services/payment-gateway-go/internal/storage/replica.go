package storage

import (
	"context"
	"database/sql"
	"fmt"
	"log"

	"github.com/ffarena/payment-gateway-go/internal/domain"
	"github.com/ffarena/payment-gateway-go/internal/models"
)

// ReplicaStore implements read/write splitting: every mutation goes to the
// primary, reads prefer the replica and fall back to the primary when the
// replica is unavailable or misses the row (replication lag).
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

// ---------------- payments ----------------

func (r *ReplicaStore) CreatePayment(ctx context.Context, payment *models.Payment) error {
	return r.getWriteStore().CreatePayment(ctx, payment)
}

func (r *ReplicaStore) GetPaymentByID(ctx context.Context, id string) (*models.Payment, error) {
	store := r.getReadStore()
	payment, err := store.GetPaymentByID(ctx, id)
	if err != nil && store != r.primary {
		return r.primary.GetPaymentByID(ctx, id)
	}
	return payment, err
}

func (r *ReplicaStore) GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error) {
	store := r.getReadStore()
	payment, err := store.GetPaymentByExternalID(ctx, externalID)
	if err != nil && store != r.primary {
		return r.primary.GetPaymentByExternalID(ctx, externalID)
	}
	return payment, err
}

func (r *ReplicaStore) GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error) {
	store := r.getReadStore()
	payment, err := store.GetPaymentByIdempotencyKey(ctx, key)
	if err != nil && store != r.primary {
		return r.primary.GetPaymentByIdempotencyKey(ctx, key)
	}
	return payment, err
}

func (r *ReplicaStore) UpdatePayment(ctx context.Context, payment *models.Payment) error {
	return r.getWriteStore().UpdatePayment(ctx, payment)
}

func (r *ReplicaStore) ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error) {
	store := r.getReadStore()
	payments, err := store.ListPaymentsByUser(ctx, userID)
	if err != nil && store != r.primary {
		return r.primary.ListPaymentsByUser(ctx, userID)
	}
	return payments, err
}

// ---------------- wallets ----------------

func (r *ReplicaStore) GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error) {
	store := r.getReadStore()
	wallet, err := store.GetWalletByUserAndCurrency(ctx, userID, currency)
	if err != nil && store != r.primary {
		return r.primary.GetWalletByUserAndCurrency(ctx, userID, currency)
	}
	return wallet, err
}

func (r *ReplicaStore) CreateWallet(ctx context.Context, wallet *models.Wallet) error {
	return r.getWriteStore().CreateWallet(ctx, wallet)
}

func (r *ReplicaStore) UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error {
	return r.getWriteStore().UpdateWalletBalance(ctx, walletID, balanceMinor)
}

// LockWalletForUpdate is a write-path concern: row locks must be taken on the
// primary, never on the (possibly lagging) replica.
func (r *ReplicaStore) LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error) {
	return r.getWriteStore().LockWalletForUpdate(ctx, walletID)
}

// ---------------- ledger ----------------

func (r *ReplicaStore) CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error {
	return r.getWriteStore().CreateLedgerEntry(ctx, entry)
}

func (r *ReplicaStore) ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error) {
	store := r.getReadStore()
	entries, err := store.ListLedgerByWallet(ctx, walletID)
	if err != nil && store != r.primary {
		return r.primary.ListLedgerByWallet(ctx, walletID)
	}
	return entries, err
}

// CalculateLedgerBalance and VerifyLedgerIntegrity are financial-consistency
// checks; they read from the primary so a lagging replica can never approve
// a movement against a stale balance.
func (r *ReplicaStore) CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error) {
	return r.getWriteStore().CalculateLedgerBalance(ctx, walletID)
}

func (r *ReplicaStore) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error) {
	return r.getWriteStore().VerifyLedgerIntegrity(ctx, walletID)
}

// ---------------- payouts ----------------

func (r *ReplicaStore) CreatePayout(ctx context.Context, payout *models.Payout) error {
	return r.getWriteStore().CreatePayout(ctx, payout)
}

func (r *ReplicaStore) GetPayoutByID(ctx context.Context, id string) (*models.Payout, error) {
	store := r.getReadStore()
	payout, err := store.GetPayoutByID(ctx, id)
	if err != nil && store != r.primary {
		return r.primary.GetPayoutByID(ctx, id)
	}
	return payout, err
}

func (r *ReplicaStore) GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error) {
	store := r.getReadStore()
	payout, err := store.GetPayoutByExternalID(ctx, externalID)
	if err != nil && store != r.primary {
		return r.primary.GetPayoutByExternalID(ctx, externalID)
	}
	return payout, err
}

func (r *ReplicaStore) GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error) {
	store := r.getReadStore()
	payout, err := store.GetPayoutByIdempotencyKey(ctx, key)
	if err != nil && store != r.primary {
		return r.primary.GetPayoutByIdempotencyKey(ctx, key)
	}
	return payout, err
}

func (r *ReplicaStore) UpdatePayout(ctx context.Context, payout *models.Payout) error {
	return r.getWriteStore().UpdatePayout(ctx, payout)
}

// ---------------- idempotency ----------------

// Idempotency records guard duplicate financial mutations: reads must hit the
// primary, otherwise a lagging replica could admit a replayed request.
func (r *ReplicaStore) GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error) {
	return r.getWriteStore().GetIdempotency(ctx, key)
}

func (r *ReplicaStore) SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error {
	return r.getWriteStore().SetIdempotency(ctx, record)
}

func (r *ReplicaStore) DeleteIdempotency(ctx context.Context, key string) error {
	return r.getWriteStore().DeleteIdempotency(ctx, key)
}

func (r *ReplicaStore) CleanupIdempotency(ctx context.Context) error {
	return r.getWriteStore().CleanupIdempotency(ctx)
}

// ---------------- lifecycle ----------------

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

// OpenReplicaDatabase opens a read-replica Store for the given DSN. The
// primary connection is passed only for driver normalization/health context;
// the replica gets its own connection pool. An empty DSN or an unreachable
// replica yields (nil, nil) so callers fall back to the primary.
func OpenReplicaDatabase(primaryDB *sql.DB, replicaURL, driver string) (Store, error) {
	if replicaURL == "" {
		return nil, nil
	}

	db, err := OpenReplicaDatabaseReal(replicaURL, driver)
	if err != nil {
		return nil, fmt.Errorf("open replica database: %w", err)
	}
	if db == nil {
		// Replica unreachable — caller falls back to primary.
		return nil, nil
	}

	switch driver {
	case "postgres", "pgsql", "postgresql":
		return NewPostgresStore(db), nil
	case "sqlite", "sqlite3":
		return NewSQLiteStore(db), nil
	default:
		return nil, fmt.Errorf("unsupported replica driver: %s", driver)
	}
}
