package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type LedgerRepository struct{store storage.Store}
func NewLedgerRepository(store storage.Store) *LedgerRepository {return &LedgerRepository{store:store}}
func (r *LedgerRepository) Create(ctx context.Context, entry *models.LedgerEntry) error {return r.store.CreateLedgerEntry(ctx,entry)}
func (r *LedgerRepository) ListByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error) {return r.store.ListLedgerByWallet(ctx,walletID)}
func (r *LedgerRepository) CalculateBalance(ctx context.Context, walletID int64) (int64, error) {return r.store.CalculateLedgerBalance(ctx,walletID)}
func (r *LedgerRepository) VerifyBalance(ctx context.Context, walletID int64) (bool, error) {return r.store.VerifyLedgerIntegrity(ctx,walletID)}
