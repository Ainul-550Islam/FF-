package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type WalletRepository struct{store storage.Store}
func NewWalletRepository(store storage.Store) *WalletRepository {return &WalletRepository{store:store}}
func (r *WalletRepository) GetByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error) {return r.store.GetWalletByUserAndCurrency(ctx,userID,currency)}
func (r *WalletRepository) Create(ctx context.Context, wallet *models.Wallet) error {return r.store.CreateWallet(ctx,wallet)}
func (r *WalletRepository) UpdateBalance(ctx context.Context, walletID int64, balance int64) error {return r.store.UpdateWalletBalance(ctx,walletID,balance)}
