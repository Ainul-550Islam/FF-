package storage
import ("context"; "fmt"; "sync"; "time"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/models")
type MemoryStore struct{
    mu sync.RWMutex
    payments map[string]*models.Payment
    paymentsByExternal map[string]*models.Payment
    paymentsByIdempotency map[string]*models.Payment
    wallets map[int64]*models.Wallet
    walletsByUser map[string]*models.Wallet
    ledger map[int64][]*models.LedgerEntry
    payouts map[string]*models.Payout
    payoutsByExternal map[string]*models.Payout
    payoutsByIdempotency map[string]*models.Payout
    idempotency map[string]*domain.IdempotencyRecord
    expiresAt map[string]time.Time
}
func NewMemoryStore() *MemoryStore{
    return &MemoryStore{
        payments: make(map[string]*models.Payment), paymentsByExternal: make(map[string]*models.Payment), paymentsByIdempotency: make(map[string]*models.Payment),
        wallets: make(map[int64]*models.Wallet), walletsByUser: make(map[string]*models.Wallet),
        ledger: make(map[int64][]*models.LedgerEntry),
        payouts: make(map[string]*models.Payout), payoutsByExternal: make(map[string]*models.Payout), payoutsByIdempotency: make(map[string]*models.Payout),
        idempotency: make(map[string]*domain.IdempotencyRecord), expiresAt: make(map[string]time.Time),
    }
}
func (m *MemoryStore) CreatePayment(ctx context.Context, payment *models.Payment) error{m.mu.Lock(); defer m.mu.Unlock(); if _,exists:=m.paymentsByExternal[payment.ExternalID]; exists{return fmt.Errorf("payment external_id already exists")}; if _,exists:=m.paymentsByIdempotency[payment.IdempotencyKey]; exists{return fmt.Errorf("idempotency key already exists")}; m.payments[payment.ID]=payment; m.paymentsByExternal[payment.ExternalID]=payment; m.paymentsByIdempotency[payment.IdempotencyKey]=payment; return nil}
func (m *MemoryStore) GetPaymentByID(ctx context.Context, id string) (*models.Payment, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.payments[id]; if !ok{return nil, fmt.Errorf("payment not found")}; return p, nil}
func (m *MemoryStore) GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.paymentsByExternal[externalID]; if !ok{return nil, fmt.Errorf("payment not found")}; return p, nil}
func (m *MemoryStore) GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.paymentsByIdempotency[key]; if !ok{return nil, fmt.Errorf("payment not found")}; return p, nil}
func (m *MemoryStore) UpdatePayment(ctx context.Context, payment *models.Payment) error{m.mu.Lock(); defer m.mu.Unlock(); m.payments[payment.ID]=payment; m.paymentsByExternal[payment.ExternalID]=payment; return nil}
func (m *MemoryStore) ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error){m.mu.RLock(); defer m.mu.RUnlock(); var result []*models.Payment; for _,p:=range m.payments{if p.UserID==userID{result=append(result,p)}}; return result, nil}
func (m *MemoryStore) GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error){m.mu.RLock(); defer m.mu.RUnlock(); key:=fmt.Sprintf("%d:%s",userID,currency); w,ok:=m.walletsByUser[key]; if !ok{return nil, fmt.Errorf("wallet not found")}; return w, nil}
func (m *MemoryStore) CreateWallet(ctx context.Context, wallet *models.Wallet) error{m.mu.Lock(); defer m.mu.Unlock(); key:=fmt.Sprintf("%d:%s",wallet.UserID,wallet.Currency); if _,exists:=m.walletsByUser[key]; exists{return fmt.Errorf("wallet already exists")}; m.wallets[wallet.ID]=wallet; m.walletsByUser[key]=wallet; return nil}
func (m *MemoryStore) UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error{m.mu.Lock(); defer m.mu.Unlock(); w,ok:=m.wallets[walletID]; if !ok{return fmt.Errorf("wallet not found")}; w.BalanceMinor=balanceMinor; return nil}
func (m *MemoryStore) LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error){m.mu.RLock(); defer m.mu.RUnlock(); w,ok:=m.wallets[walletID]; if !ok{return nil, fmt.Errorf("wallet not found")}; return w, nil}
func (m *MemoryStore) CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error{m.mu.Lock(); defer m.mu.Unlock(); m.ledger[entry.WalletID]=append(m.ledger[entry.WalletID],entry); return nil}
func (m *MemoryStore) ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error){m.mu.RLock(); defer m.mu.RUnlock(); return m.ledger[walletID], nil}
func (m *MemoryStore) CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error){m.mu.RLock(); defer m.mu.RUnlock(); var credits,debits int64; for _,e:=range m.ledger[walletID]{if e.Direction=="credit"{credits+=e.AmountMinor} else {debits+=e.AmountMinor}}; return credits-debits, nil}
func (m *MemoryStore) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error){m.mu.RLock(); defer m.mu.RUnlock(); entries:=m.ledger[walletID]; var running int64; for _,e:=range entries{if e.Direction=="credit"{running+=e.AmountMinor} else {running-=e.AmountMinor}; if running!=e.BalanceAfterMinor{return false, nil}}; w,ok:=m.wallets[walletID]; if !ok{return false, fmt.Errorf("wallet not found")}; return running==w.BalanceMinor, nil}
func (m *MemoryStore) CreatePayout(ctx context.Context, payout *models.Payout) error{m.mu.Lock(); defer m.mu.Unlock(); if _,exists:=m.payoutsByExternal[payout.ExternalID]; exists{return fmt.Errorf("payout external_id exists")}; if _,exists:=m.payoutsByIdempotency[payout.IdempotencyKey]; exists{return fmt.Errorf("payout idempotency key exists")}; m.payouts[payout.ID]=payout; m.payoutsByExternal[payout.ExternalID]=payout; m.payoutsByIdempotency[payout.IdempotencyKey]=payout; return nil}
func (m *MemoryStore) GetPayoutByID(ctx context.Context, id string) (*models.Payout, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.payouts[id]; if !ok{return nil, fmt.Errorf("payout not found")}; return p, nil}
func (m *MemoryStore) GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.payoutsByExternal[externalID]; if !ok{return nil, fmt.Errorf("payout not found")}; return p, nil}
func (m *MemoryStore) GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error){m.mu.RLock(); defer m.mu.RUnlock(); p,ok:=m.payoutsByIdempotency[key]; if !ok{return nil, fmt.Errorf("payout not found")}; return p, nil}
func (m *MemoryStore) UpdatePayout(ctx context.Context, payout *models.Payout) error{m.mu.Lock(); defer m.mu.Unlock(); m.payouts[payout.ID]=payout; return nil}
func (m *MemoryStore) GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error){m.mu.RLock(); defer m.mu.RUnlock(); r,ok:=m.idempotency[key]; if !ok{return nil, fmt.Errorf("idempotency record not found")}; if time.Now().After(r.ExpiresAt){return nil, fmt.Errorf("idempotency record expired")}; return r, nil}
func (m *MemoryStore) SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error{m.mu.Lock(); defer m.mu.Unlock(); m.idempotency[record.Key]=record; m.expiresAt[record.Key]=record.ExpiresAt; return nil}
func (m *MemoryStore) DeleteIdempotency(ctx context.Context, key string) error{m.mu.Lock(); defer m.mu.Unlock(); delete(m.idempotency,key); delete(m.expiresAt,key); return nil}
func (m *MemoryStore) CleanupIdempotency(ctx context.Context) error{m.mu.Lock(); defer m.mu.Unlock(); now:=time.Now(); for key,exp:=range m.expiresAt{if now.After(exp){delete(m.idempotency,key); delete(m.expiresAt,key)}}; return nil}
func (m *MemoryStore) HealthCheck(ctx context.Context) error {return nil}
func (m *MemoryStore) Close() error {return nil}
func (m *MemoryStore) Migrate(ctx context.Context) error {return nil}
