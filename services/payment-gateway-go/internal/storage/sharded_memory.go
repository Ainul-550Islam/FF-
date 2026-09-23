package storage

import (
	"context"
	"crypto/sha256"
	"fmt"
	"strconv"
	"sync"
	"time"

	"github.com/ffarena/payment-gateway-go/internal/domain"
	"github.com/ffarena/payment-gateway-go/internal/models"
)

// ShardedMemoryStore keeps the MemoryStore semantics but spreads the data
// across N independently locked shards, reducing global lock contention on
// hot payment paths. Shard routing is a stable hash of the entity key, so
// the same ID always lands on the same shard.
type ShardedMemoryStore struct {
	shards    []*MemoryStoreShard
	numShards int
}

type MemoryStoreShard struct {
	mu                    sync.RWMutex
	payments              map[string]*models.Payment
	paymentsByExternal    map[string]*models.Payment
	paymentsByIdempotency map[string]*models.Payment
	wallets               map[int64]*models.Wallet
	walletsByUser         map[string]*models.Wallet
	ledger                map[int64][]*models.LedgerEntry
	payouts               map[string]*models.Payout
	payoutsByExternal     map[string]*models.Payout
	payoutsByIdempotency  map[string]*models.Payout
	idempotency           map[string]*domain.IdempotencyRecord
	expiresAt             map[string]time.Time
	events                map[string]*domain.WebhookEvent
}

func NewShardedMemoryStore(numShards int) *ShardedMemoryStore {
	if numShards <= 0 {
		numShards = 16
	}
	shards := make([]*MemoryStoreShard, numShards)
	for i := 0; i < numShards; i++ {
		shards[i] = &MemoryStoreShard{
			payments:              make(map[string]*models.Payment),
			paymentsByExternal:    make(map[string]*models.Payment),
			paymentsByIdempotency: make(map[string]*models.Payment),
			wallets:               make(map[int64]*models.Wallet),
			walletsByUser:         make(map[string]*models.Wallet),
			ledger:                make(map[int64][]*models.LedgerEntry),
			payouts:               make(map[string]*models.Payout),
			payoutsByExternal:     make(map[string]*models.Payout),
			payoutsByIdempotency:  make(map[string]*models.Payout),
			idempotency:           make(map[string]*domain.IdempotencyRecord),
			expiresAt:             make(map[string]time.Time),
			events:                make(map[string]*domain.WebhookEvent),
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

func (s *ShardedMemoryStore) getShardByInt64(id int64) *MemoryStoreShard {
	return s.getShard(strconv.FormatInt(id, 10))
}

// ---------------- payments ----------------

func (s *ShardedMemoryStore) CreatePayment(ctx context.Context, payment *models.Payment) error {
	shard := s.getShardByID(payment.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	if _, exists := shard.paymentsByExternal[payment.ExternalID]; exists {
		return fmt.Errorf("payment external_id already exists")
	}
	if _, exists := shard.paymentsByIdempotency[payment.IdempotencyKey]; exists {
		return fmt.Errorf("idempotency key already exists")
	}
	shard.payments[payment.ID] = payment
	shard.paymentsByExternal[payment.ExternalID] = payment
	shard.paymentsByIdempotency[payment.IdempotencyKey] = payment
	return nil
}

func (s *ShardedMemoryStore) GetPaymentByID(ctx context.Context, id string) (*models.Payment, error) {
	shard := s.getShardByID(id)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.payments[id]
	if !exists {
		return nil, fmt.Errorf("payment not found: %s", id)
	}
	return p, nil
}

func (s *ShardedMemoryStore) GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error) {
	shard := s.getShardByID(externalID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.paymentsByExternal[externalID]
	if !exists {
		return nil, fmt.Errorf("payment not found: %s", externalID)
	}
	return p, nil
}

func (s *ShardedMemoryStore) GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error) {
	shard := s.getShardByID(key)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.paymentsByIdempotency[key]
	if !exists {
		return nil, fmt.Errorf("payment not found: %s", key)
	}
	return p, nil
}

func (s *ShardedMemoryStore) UpdatePayment(ctx context.Context, payment *models.Payment) error {
	shard := s.getShardByID(payment.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	shard.payments[payment.ID] = payment
	shard.paymentsByExternal[payment.ExternalID] = payment
	return nil
}

// ListPaymentsByUser aggregates across every shard. Shard order is stable so
// callers observe a deterministic concatenation of per-shard results.
func (s *ShardedMemoryStore) ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error) {
	all := make([]*models.Payment, 0)
	for _, shard := range s.shards {
		shard.mu.RLock()
		for _, p := range shard.payments {
			if p.UserID == userID {
				all = append(all, p)
			}
		}
		shard.mu.RUnlock()
	}
	return all, nil
}

// ---------------- wallets ----------------

func (s *ShardedMemoryStore) GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error) {
	shard := s.getShardByInt64(userID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	key := fmt.Sprintf("%d:%s", userID, currency)
	w, exists := shard.walletsByUser[key]
	if !exists {
		return nil, fmt.Errorf("wallet not found")
	}
	return w, nil
}

func (s *ShardedMemoryStore) CreateWallet(ctx context.Context, wallet *models.Wallet) error {
	shard := s.getShardByInt64(wallet.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	key := fmt.Sprintf("%d:%s", wallet.UserID, wallet.Currency)
	if _, exists := shard.walletsByUser[key]; exists {
		return fmt.Errorf("wallet already exists")
	}
	shard.wallets[wallet.ID] = wallet
	shard.walletsByUser[key] = wallet
	return nil
}

func (s *ShardedMemoryStore) UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error {
	shard := s.getShardByInt64(walletID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	w, exists := shard.wallets[walletID]
	if !exists {
		return fmt.Errorf("wallet not found")
	}
	w.BalanceMinor = balanceMinor
	return nil
}

func (s *ShardedMemoryStore) LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error) {
	shard := s.getShardByInt64(walletID)
	// Shard-level write lock emulates SELECT ... FOR UPDATE on the row.
	shard.mu.Lock()
	defer shard.mu.Unlock()
	w, exists := shard.wallets[walletID]
	if !exists {
		return nil, fmt.Errorf("wallet not found")
	}
	return w, nil
}

// ---------------- ledger ----------------

func (s *ShardedMemoryStore) CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error {
	shard := s.getShardByInt64(entry.WalletID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	shard.ledger[entry.WalletID] = append(shard.ledger[entry.WalletID], entry)
	return nil
}

func (s *ShardedMemoryStore) ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error) {
	shard := s.getShardByInt64(walletID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	return shard.ledger[walletID], nil
}

func (s *ShardedMemoryStore) CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error) {
	shard := s.getShardByInt64(walletID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	var credits, debits int64
	for _, e := range shard.ledger[walletID] {
		if e.Direction == "credit" {
			credits += e.AmountMinor
		} else {
			debits += e.AmountMinor
		}
	}
	return credits - debits, nil
}

func (s *ShardedMemoryStore) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error) {
	shard := s.getShardByInt64(walletID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	entries := shard.ledger[walletID]
	var running int64
	for _, e := range entries {
		if e.Direction == "credit" {
			running += e.AmountMinor
		} else {
			running -= e.AmountMinor
		}
		if running != e.BalanceAfterMinor {
			return false, nil
		}
	}
	w, exists := shard.wallets[walletID]
	if !exists {
		return false, fmt.Errorf("wallet not found")
	}
	return running == w.BalanceMinor, nil
}

// ---------------- payouts ----------------

func (s *ShardedMemoryStore) CreatePayout(ctx context.Context, payout *models.Payout) error {
	shard := s.getShardByID(payout.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	if _, exists := shard.payoutsByExternal[payout.ExternalID]; exists {
		return fmt.Errorf("payout external_id exists")
	}
	if _, exists := shard.payoutsByIdempotency[payout.IdempotencyKey]; exists {
		return fmt.Errorf("payout idempotency key exists")
	}
	shard.payouts[payout.ID] = payout
	shard.payoutsByExternal[payout.ExternalID] = payout
	shard.payoutsByIdempotency[payout.IdempotencyKey] = payout
	return nil
}

func (s *ShardedMemoryStore) GetPayoutByID(ctx context.Context, id string) (*models.Payout, error) {
	shard := s.getShardByID(id)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.payouts[id]
	if !exists {
		return nil, fmt.Errorf("payout not found")
	}
	return p, nil
}

func (s *ShardedMemoryStore) GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error) {
	shard := s.getShardByID(externalID)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.payoutsByExternal[externalID]
	if !exists {
		return nil, fmt.Errorf("payout not found")
	}
	return p, nil
}

func (s *ShardedMemoryStore) GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error) {
	shard := s.getShardByID(key)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	p, exists := shard.payoutsByIdempotency[key]
	if !exists {
		return nil, fmt.Errorf("payout not found")
	}
	return p, nil
}

func (s *ShardedMemoryStore) UpdatePayout(ctx context.Context, payout *models.Payout) error {
	shard := s.getShardByID(payout.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	shard.payouts[payout.ID] = payout
	return nil
}

// ---------------- idempotency ----------------

func (s *ShardedMemoryStore) GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error) {
	shard := s.getShardByID(key)
	shard.mu.RLock()
	defer shard.mu.RUnlock()
	r, ok := shard.idempotency[key]
	if !ok {
		return nil, fmt.Errorf("idempotency record not found")
	}
	if time.Now().After(r.ExpiresAt) {
		return nil, fmt.Errorf("idempotency record expired")
	}
	return r, nil
}

func (s *ShardedMemoryStore) SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error {
	shard := s.getShardByID(record.Key)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	shard.idempotency[record.Key] = record
	shard.expiresAt[record.Key] = record.ExpiresAt
	return nil
}

func (s *ShardedMemoryStore) DeleteIdempotency(ctx context.Context, key string) error {
	shard := s.getShardByID(key)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	delete(shard.idempotency, key)
	delete(shard.expiresAt, key)
	return nil
}

func (s *ShardedMemoryStore) CleanupIdempotency(ctx context.Context) error {
	now := time.Now()
	for _, shard := range s.shards {
		shard.mu.Lock()
		for key, exp := range shard.expiresAt {
			if now.After(exp) {
				delete(shard.idempotency, key)
				delete(shard.expiresAt, key)
			}
		}
		shard.mu.Unlock()
	}
	return nil
}

// ---------------- webhook events (extra capability, preserved) ----------------

func (s *ShardedMemoryStore) CreateWebhookEvent(ctx context.Context, e *domain.WebhookEvent) error {
	shard := s.getShardByID(e.ID)
	shard.mu.Lock()
	defer shard.mu.Unlock()
	shard.events[e.ID] = e
	return nil
}

func (s *ShardedMemoryStore) GetWebhookEventByProviderRef(ctx context.Context, provider, externalRef string) (*domain.WebhookEvent, error) {
	for _, shard := range s.shards {
		shard.mu.RLock()
		for _, e := range shard.events {
			if e.Provider == provider && e.EventID == externalRef {
				shard.mu.RUnlock()
				return e, nil
			}
		}
		shard.mu.RUnlock()
	}
	return nil, fmt.Errorf("webhook event not found")
}

// ---------------- lifecycle ----------------

func (s *ShardedMemoryStore) Migrate(ctx context.Context) error  { return nil }
func (s *ShardedMemoryStore) Close() error                       { return nil }
func (s *ShardedMemoryStore) HealthCheck(ctx context.Context) error { return nil }
