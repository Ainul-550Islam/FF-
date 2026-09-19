package services
import ("context"; "fmt"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type WalletService struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewWalletService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *WalletService {return &WalletService{store:store,metrics:metrics,logger:logger}}
func (s *WalletService) Credit(ctx context.Context, userID int64, amountMinor int64, currency, referenceType, referenceID, idempotencyKey string) (*models.LedgerEntry, error){
    if amountMinor<=0{return nil, fmt.Errorf("amount must be positive")}
    wallet,err:=s.store.GetWalletByUserAndCurrency(ctx,userID,currency)
    if err!=nil{
        wallet=&models.Wallet{UserID:userID,Currency:currency,BalanceMinor:0}
        if err:=s.store.CreateWallet(ctx,wallet); err!=nil{return nil, fmt.Errorf("create wallet: %w",err)}
    }
    locked,err:=s.store.LockWalletForUpdate(ctx,wallet.ID)
    if err!=nil{return nil, fmt.Errorf("lock wallet: %w",err)}
    newBalance:=locked.BalanceMinor+amountMinor
    entry:=&models.LedgerEntry{WalletID:wallet.ID,UserID:userID,Direction:"credit",AmountMinor:amountMinor,BalanceAfterMinor:newBalance,ReferenceType:referenceType,ReferenceID:referenceID,IdempotencyKey:idempotencyKey}
    if entry.IdempotencyKey==""{entry.IdempotencyKey=uuid.New().String()}
    if existingEntries,err:=s.store.ListLedgerByWallet(ctx,wallet.ID); err==nil{for _,e:=range existingEntries{if e.IdempotencyKey==entry.IdempotencyKey{return e, nil}}}
    if err:=s.store.CreateLedgerEntry(ctx,entry); err!=nil{return nil, fmt.Errorf("create ledger: %w",err)}
    if err:=s.store.UpdateWalletBalance(ctx,wallet.ID,newBalance); err!=nil{return nil, fmt.Errorf("update balance: %w",err)}
    s.metrics.Increment("wallet.credit",map[string]string{"currency":currency})
    return entry, nil
}
func (s *WalletService) Debit(ctx context.Context, userID int64, amountMinor int64, currency, referenceType, referenceID, idempotencyKey string) (*models.LedgerEntry, error){
    if amountMinor<=0{return nil, fmt.Errorf("amount must be positive")}
    wallet,err:=s.store.GetWalletByUserAndCurrency(ctx,userID,currency)
    if err!=nil{return nil, fmt.Errorf("wallet not found")}
    locked,err:=s.store.LockWalletForUpdate(ctx,wallet.ID)
    if err!=nil{return nil, fmt.Errorf("lock wallet: %w",err)}
    if locked.BalanceMinor<amountMinor{return nil, fmt.Errorf("insufficient funds")}
    newBalance:=locked.BalanceMinor-amountMinor
    entry:=&models.LedgerEntry{WalletID:wallet.ID,UserID:userID,Direction:"debit",AmountMinor:amountMinor,BalanceAfterMinor:newBalance,ReferenceType:referenceType,ReferenceID:referenceID,IdempotencyKey:idempotencyKey}
    if entry.IdempotencyKey==""{entry.IdempotencyKey=uuid.New().String()}
    if existingEntries,err:=s.store.ListLedgerByWallet(ctx,wallet.ID); err==nil{for _,e:=range existingEntries{if e.IdempotencyKey==entry.IdempotencyKey{return e, nil}}}
    if err:=s.store.CreateLedgerEntry(ctx,entry); err!=nil{return nil, fmt.Errorf("create ledger: %w",err)}
    if err:=s.store.UpdateWalletBalance(ctx,wallet.ID,newBalance); err!=nil{return nil, fmt.Errorf("update balance: %w",err)}
    s.metrics.Increment("wallet.debit",map[string]string{"currency":currency})
    return entry, nil
}
func (s *WalletService) GetBalance(ctx context.Context, userID int64, currency string) (int64, error){wallet,err:=s.store.GetWalletByUserAndCurrency(ctx,userID,currency); if err!=nil{return 0, err}; return wallet.BalanceMinor, nil}
func (s *WalletService) VerifyIntegrity(ctx context.Context, walletID int64) (bool, error){return s.store.VerifyLedgerIntegrity(ctx,walletID)}
