# R9 Full File Content Part 18 - Files 256-270

Total files in this part: 15

## File: ./services/payment-gateway-go/internal/repository/ledger_repo.go

```
package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type LedgerRepository struct{store storage.Store}
func NewLedgerRepository(store storage.Store) *LedgerRepository {return &LedgerRepository{store:store}}
func (r *LedgerRepository) Create(ctx context.Context, entry *models.LedgerEntry) error {return r.store.CreateLedgerEntry(ctx,entry)}
func (r *LedgerRepository) ListByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error) {return r.store.ListLedgerByWallet(ctx,walletID)}
func (r *LedgerRepository) CalculateBalance(ctx context.Context, walletID int64) (int64, error) {return r.store.CalculateLedgerBalance(ctx,walletID)}
func (r *LedgerRepository) VerifyBalance(ctx context.Context, walletID int64) (bool, error) {return r.store.VerifyLedgerIntegrity(ctx,walletID)}
```

## File: ./services/payment-gateway-go/internal/repository/payment_repo.go

```
package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PaymentRepository struct{store storage.Store}
func NewPaymentRepository(store storage.Store) *PaymentRepository {return &PaymentRepository{store:store}}
func (r *PaymentRepository) Create(ctx context.Context, payment *models.Payment) error {return r.store.CreatePayment(ctx,payment)}
func (r *PaymentRepository) GetByID(ctx context.Context, id string) (*models.Payment, error) {return r.store.GetPaymentByID(ctx,id)}
func (r *PaymentRepository) GetByExternalID(ctx context.Context, externalID string) (*models.Payment, error) {return r.store.GetPaymentByExternalID(ctx,externalID)}
func (r *PaymentRepository) Update(ctx context.Context, payment *models.Payment) error {return r.store.UpdatePayment(ctx,payment)}
```

## File: ./services/payment-gateway-go/internal/repository/payout_repo.go

```
package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PayoutRepository struct{store storage.Store}
func NewPayoutRepository(store storage.Store) *PayoutRepository {return &PayoutRepository{store:store}}
func (r *PayoutRepository) Create(ctx context.Context, payout *models.Payout) error {return r.store.CreatePayout(ctx,payout)}
func (r *PayoutRepository) GetByID(ctx context.Context, id string) (*models.Payout, error) {return r.store.GetPayoutByID(ctx,id)}
func (r *PayoutRepository) Update(ctx context.Context, payout *models.Payout) error {return r.store.UpdatePayout(ctx,payout)}
```

## File: ./services/payment-gateway-go/internal/repository/wallet_repo.go

```
package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type WalletRepository struct{store storage.Store}
func NewWalletRepository(store storage.Store) *WalletRepository {return &WalletRepository{store:store}}
func (r *WalletRepository) GetByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error) {return r.store.GetWalletByUserAndCurrency(ctx,userID,currency)}
func (r *WalletRepository) Create(ctx context.Context, wallet *models.Wallet) error {return r.store.CreateWallet(ctx,wallet)}
func (r *WalletRepository) UpdateBalance(ctx context.Context, walletID int64, balance int64) error {return r.store.UpdateWalletBalance(ctx,walletID,balance)}
```

## File: ./services/payment-gateway-go/internal/retry/retry.go

```
package retry
import ("time")
func RetryWithBackoff(fn func() error, maxRetries int, baseDelay time.Duration) error{
    var lastErr error
    for i:=0;i<maxRetries;i++{
        if err:=fn(); err==nil{return nil} else {lastErr=err; if i<maxRetries-1{delay:=baseDelay*time.Duration(1<<uint(i)); if delay>30*time.Second{delay=30*time.Second}; time.Sleep(delay)}}
    }
    return lastErr
}
func ExponentialBackoff(attempt int, baseDelay time.Duration) time.Duration{
    delay:=baseDelay*time.Duration(1<<uint(attempt))
    if delay>30*time.Second{delay=30*time.Second}
    return delay
}
```

## File: ./services/payment-gateway-go/internal/security/hmac.go

```
package security
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex")
func VerifyHMAC(payload []byte, signature, secret string) bool {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write(payload)
    expected:=hex.EncodeToString(mac.Sum(nil))
    return hmac.Equal([]byte(expected),[]byte(signature))
}
func GenerateHMAC(secret, message string) string {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write([]byte(message)); return hex.EncodeToString(mac.Sum(nil))
}
```

## File: ./services/payment-gateway-go/internal/security/jwt.go

```
package security
import ("errors"; "time"; "github.com/golang-jwt/jwt/v5")
type Claims struct{UserID int64 `json:"user_id"`; ServiceID string `json:"service_id"`; jwt.RegisteredClaims}
func GenerateJWT(secret string, userID int64, serviceID string, expiry time.Duration) (string, error){
    claims:=Claims{UserID:userID,ServiceID:serviceID,RegisteredClaims:jwt.RegisteredClaims{ExpiresAt:jwt.NewNumericDate(time.Now().Add(expiry)),IssuedAt:jwt.NewNumericDate(time.Now()),Issuer:serviceID}}
    token:=jwt.NewWithClaims(jwt.SigningMethodHS256,claims)
    return token.SignedString([]byte(secret))
}
func VerifyJWT(tokenString, secret string) (*Claims, error){
    token,err:=jwt.ParseWithClaims(tokenString,&Claims{},func(token *jwt.Token) (interface{}, error){return []byte(secret), nil})
    if err!=nil{return nil, err}
    if claims,ok:=token.Claims.(*Claims); ok&&token.Valid{return claims, nil}
    return nil, errors.New("invalid token")
}
```

## File: ./services/payment-gateway-go/internal/security/service_auth.go

```
package security
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex"; "fmt"; "net/http"; "strconv"; "time"; "github.com/google/uuid")
func SignRequest(secret, method, path, body string, timestamp int64, nonce string) string {
    message:=fmt.Sprintf("%s:%s:%s:%d:%s",method,path,body,timestamp,nonce)
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write([]byte(message)); return hex.EncodeToString(mac.Sum(nil))
}
func VerifyRequest(secret, method, path, body, signature string, timestamp int64, nonce string, toleranceSeconds int64) error {
    now:=time.Now().Unix()
    if abs(now-timestamp)>toleranceSeconds{return fmt.Errorf("timestamp out of tolerance")}
    expected:=SignRequest(secret,method,path,body,timestamp,nonce)
    if !hmac.Equal([]byte(expected),[]byte(signature)){return fmt.Errorf("invalid signature")}
    return nil
}
func GenerateTimestamp() int64 {return time.Now().Unix()}
func ValidateTimestamp(timestamp int64, toleranceSeconds int64) error {
    now:=time.Now().Unix()
    if abs(now-timestamp)>toleranceSeconds{return fmt.Errorf("timestamp out of tolerance")}
    return nil
}
func GenerateNonce() string {return uuid.New().String()}
func GenerateHeaders(secret, method, path, body string) map[string]string {
    timestamp:=GenerateTimestamp(); nonce:=GenerateNonce(); signature:=SignRequest(secret,method,path,body,timestamp,nonce)
    return map[string]string{"X-Timestamp":strconv.FormatInt(timestamp,10),"X-Nonce":nonce,"X-Signature":signature,"X-Service-ID":"payment-gateway-go"}
}
func abs(x int64) int64 {if x<0{return -x}; return x}
func SignRequestHTTP(r *http.Request, secret, body string){
    timestamp:=GenerateTimestamp(); nonce:=GenerateNonce(); signature:=SignRequest(secret,r.Method,r.URL.Path,body,timestamp,nonce)
    r.Header.Set("X-Timestamp",strconv.FormatInt(timestamp,10)); r.Header.Set("X-Nonce",nonce); r.Header.Set("X-Signature",signature)
}
```

## File: ./services/payment-gateway-go/internal/services/payment_service.go

```
package services
import ("context"; "fmt"; "github.com/ffarena/payment-gateway-go/internal/manager"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/providers"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type PaymentService struct{manager *manager.Manager; store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPaymentService(mgr *manager.Manager, store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PaymentService {return &PaymentService{manager:mgr,store:store,metrics:metrics,logger:logger}}
func (s *PaymentService) CreatePayment(ctx context.Context, req providers.CreatePaymentRequest) (*models.Payment, error){
    if existing,err:=s.store.GetPaymentByIdempotencyKey(ctx,req.IdempotencyKey); err==nil&&existing!=nil{return existing, nil}
    provider,ok:=s.manager.Get(req.Provider)
    if !ok{return nil, fmt.Errorf("unsupported provider: %s",req.Provider)}
    if !provider.SupportsCurrency(req.Currency){return nil, fmt.Errorf("currency %s not supported by %s",req.Currency,req.Provider)}
    resp,err:=provider.CreatePayment(ctx,req)
    if err!=nil{s.metrics.Increment("payment.create.failed",map[string]string{"provider":req.Provider}); return nil, err}
    payment:=&models.Payment{ID:uuid.New().String(),UserID:req.UserID,Provider:req.Provider,ExternalID:req.ExternalID,AmountMinor:req.AmountMinor,Currency:req.Currency,Status:resp.Status,IdempotencyKey:req.IdempotencyKey}
    if err:=s.store.CreatePayment(ctx,payment); err!=nil{return nil, fmt.Errorf("store payment: %w",err)}
    s.metrics.Increment("payment.created",map[string]string{"provider":req.Provider})
    s.logger.Info("payment created",map[string]interface{}{"payment_id":payment.ID,"provider":req.Provider,"user_id":req.UserID})
    return payment, nil
}
func (s *PaymentService) QueryPayment(ctx context.Context, externalID string) (*models.Payment, error){return s.store.GetPaymentByExternalID(ctx,externalID)}
```

## File: ./services/payment-gateway-go/internal/services/payout_service.go

```
package services
import ("context"; "fmt"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type PayoutService struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPayoutService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PayoutService {return &PayoutService{store:store,metrics:metrics,logger:logger}}
func (s *PayoutService) CreatePayout(ctx context.Context, userID int64, amountMinor int64, currency, externalID, idempotencyKey string) (*models.Payout, error){
    if amountMinor<=0{return nil, fmt.Errorf("amount must be positive")}
    if existing,err:=s.store.GetPayoutByIdempotencyKey(ctx,idempotencyKey); err==nil&&existing!=nil{return existing, nil}
    payout:=&models.Payout{ID:uuid.New().String(),UserID:userID,AmountMinor:amountMinor,Currency:currency,Status:"pending",ExternalID:externalID,Provider:"manual",IdempotencyKey:idempotencyKey}
    if payout.ExternalID==""{payout.ExternalID=uuid.New().String()}
    if payout.IdempotencyKey==""{payout.IdempotencyKey=uuid.New().String()}
    if err:=s.store.CreatePayout(ctx,payout); err!=nil{return nil, err}
    s.metrics.Increment("payout.created",nil)
    return payout, nil
}
```

## File: ./services/payment-gateway-go/internal/services/wallet_service.go

```
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
```

## File: ./services/payment-gateway-go/internal/settlement/service.go

```
package settlement
import ("context"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type Service struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *Service {return &Service{store:store,metrics:metrics,logger:logger}}
type Settlement struct{ID string `json:"id"`; TournamentID int64 `json:"tournament_id"`; TotalAmountMinor int64 `json:"total_amount_minor"`; Currency string `json:"currency"`; Status string `json:"status"`; IdempotencyKey string `json:"idempotency_key"`; CompletedAt *time.Time `json:"completed_at,omitempty"`; CreatedAt time.Time `json:"created_at"`}
func (s *Service) CreateSettlement(ctx context.Context, tournamentID int64, totalAmountMinor int64, currency, idempotencyKey string) (*Settlement, error){
    settlement:=&Settlement{ID:uuid.New().String(),TournamentID:tournamentID,TotalAmountMinor:totalAmountMinor,Currency:currency,Status:"pending",IdempotencyKey:idempotencyKey,CreatedAt:time.Now()}
    if settlement.IdempotencyKey==""{settlement.IdempotencyKey=uuid.New().String()}
    s.metrics.Increment("settlement.created",nil)
    s.logger.Info("settlement created",map[string]interface{}{"settlement_id":settlement.ID,"tournament_id":tournamentID})
    return settlement, nil
}
func (s *Service) Complete(ctx context.Context, settlementID string) error{s.metrics.Increment("settlement.completed",nil); return nil}
func (s *Service) DistributePrizes(ctx context.Context, settlementID string, distributions map[int64]int64) error{
    for userID,amount:=range distributions{if amount<=0{return fmt.Errorf("invalid amount for user %d",userID)}}
    s.metrics.Increment("settlement.prize_distribution",nil)
    return nil
}
```

## File: ./services/payment-gateway-go/internal/storage/interface.go

```
package storage
import ("context"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/models")
type PaymentStore interface{CreatePayment(ctx context.Context, payment *models.Payment) error; GetPaymentByID(ctx context.Context, id string) (*models.Payment, error); GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error); GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error); UpdatePayment(ctx context.Context, payment *models.Payment) error; ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error)}
type WalletStore interface{GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error); CreateWallet(ctx context.Context, wallet *models.Wallet) error; UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error; LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error)}
type LedgerStore interface{CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error; ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error); CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error); VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error)}
type PayoutStore interface{CreatePayout(ctx context.Context, payout *models.Payout) error; GetPayoutByID(ctx context.Context, id string) (*models.Payout, error); GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error); GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error); UpdatePayout(ctx context.Context, payout *models.Payout) error}
type IdempotencyStore interface{GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error); SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error; DeleteIdempotency(ctx context.Context, key string) error; CleanupIdempotency(ctx context.Context) error}
type Store interface{PaymentStore; WalletStore; LedgerStore; PayoutStore; IdempotencyStore; HealthCheck(ctx context.Context) error; Close() error; Migrate(ctx context.Context) error}
```

## File: ./services/payment-gateway-go/internal/storage/memory.go

```
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
```

## File: ./services/payment-gateway-go/internal/storage/postgres.go

```
package storage
import ("context"; "database/sql"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/models")
type PostgresStore struct{db *sql.DB}
func NewPostgresStore(db *sql.DB) *PostgresStore {return &PostgresStore{db:db}}
func (s *PostgresStore) Migrate(ctx context.Context) error {
    queries:=[]string{
        `CREATE TABLE IF NOT EXISTS payments (id TEXT PRIMARY KEY, user_id BIGINT NOT NULL, wallet_id BIGINT, provider TEXT NOT NULL, external_id TEXT UNIQUE NOT NULL, provider_reference TEXT UNIQUE, amount_minor BIGINT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, idempotency_key TEXT UNIQUE NOT NULL, idempotency_fingerprint TEXT, metadata JSONB, authorized_at TIMESTAMPTZ, succeeded_at TIMESTAMPTZ, failed_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_user_id ON payments(user_id)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_external_id ON payments(external_id)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_idempotency_key ON payments(idempotency_key)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_status ON payments(status)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_provider ON payments(provider)`,
        `CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments(created_at)`,
        `CREATE TABLE IF NOT EXISTS wallets (id BIGSERIAL PRIMARY KEY, user_id BIGINT NOT NULL, currency TEXT NOT NULL, balance_minor BIGINT NOT NULL DEFAULT 0, is_locked BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, UNIQUE(user_id, currency))`,
        `CREATE INDEX IF NOT EXISTS idx_wallets_user_id ON wallets(user_id)`,
        `CREATE TABLE IF NOT EXISTS ledger_entries (id BIGSERIAL PRIMARY KEY, wallet_id BIGINT NOT NULL REFERENCES wallets(id), user_id BIGINT NOT NULL, direction TEXT NOT NULL, amount_minor BIGINT NOT NULL, balance_after_minor BIGINT NOT NULL, reference_type TEXT, reference_id TEXT, idempotency_key TEXT UNIQUE, metadata JSONB, created_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_ledger_wallet_id ON ledger_entries(wallet_id)`,
        `CREATE INDEX IF NOT EXISTS idx_ledger_created_at ON ledger_entries(created_at)`,
        `CREATE INDEX IF NOT EXISTS idx_ledger_reference ON ledger_entries(reference_id)`,
        `CREATE TABLE IF NOT EXISTS payouts (id TEXT PRIMARY KEY, user_id BIGINT NOT NULL, tournament_id BIGINT, amount_minor BIGINT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, external_id TEXT UNIQUE NOT NULL, provider TEXT NOT NULL, idempotency_key TEXT UNIQUE NOT NULL, metadata JSONB, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_payouts_user_id ON payouts(user_id)`,
        `CREATE INDEX IF NOT EXISTS idx_payouts_external_id ON payouts(external_id)`,
        `CREATE INDEX IF NOT EXISTS idx_payouts_idempotency_key ON payouts(idempotency_key)`,
        `CREATE TABLE IF NOT EXISTS idempotency_records (key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, operation TEXT NOT NULL, user_id BIGINT, request_body JSONB, response_body JSONB, status_code INT, expires_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_idempotency_expires_at ON idempotency_records(expires_at)`,
        `CREATE INDEX IF NOT EXISTS idx_idempotency_operation ON idempotency_records(operation)`,
        `CREATE TABLE IF NOT EXISTS webhook_events (id TEXT PRIMARY KEY, provider TEXT NOT NULL, event_type TEXT, event_id TEXT UNIQUE NOT NULL, payload JSONB, signature TEXT, state TEXT NOT NULL, attempts INT NOT NULL DEFAULT 0, last_error TEXT, processed_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_webhook_event_id ON webhook_events(event_id)`,
        `CREATE INDEX IF NOT EXISTS idx_webhook_provider ON webhook_events(provider)`,
        `CREATE INDEX IF NOT EXISTS idx_webhook_state ON webhook_events(state)`,
        `CREATE TABLE IF NOT EXISTS financial_settlements (id TEXT PRIMARY KEY, tournament_id BIGINT NOT NULL, total_amount_minor BIGINT NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, idempotency_key TEXT UNIQUE NOT NULL, metadata JSONB, completed_at TIMESTAMPTZ, created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL)`,
        `CREATE INDEX IF NOT EXISTS idx_settlements_tournament_id ON financial_settlements(tournament_id)`,
        `CREATE INDEX IF NOT EXISTS idx_settlements_idempotency_key ON financial_settlements(idempotency_key)`,
    }
    for _,q:=range queries{if _,err:=s.db.ExecContext(ctx,q); err!=nil{return fmt.Errorf("migrate failed: %w, query: %s",err,q)}}
    return nil
}
func (s *PostgresStore) CreatePayment(ctx context.Context, payment *models.Payment) error{query:=`INSERT INTO payments (id, user_id, wallet_id, provider, external_id, amount_minor, currency, status, idempotency_key, created_at, updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)`; _,err:=s.db.ExecContext(ctx,query,payment.ID,payment.UserID,payment.WalletID,payment.Provider,payment.ExternalID,payment.AmountMinor,payment.Currency,payment.Status,payment.IdempotencyKey,time.Now().UTC(),time.Now().UTC()); return err}
func (s *PostgresStore) GetPaymentByID(ctx context.Context, id string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE id=$1`,id).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE external_id=$1`,externalID).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE idempotency_key=$1`,key).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) UpdatePayment(ctx context.Context, payment *models.Payment) error{_,err:=s.db.ExecContext(ctx,`UPDATE payments SET status=$1, updated_at=$2 WHERE id=$3`,payment.Status,time.Now().UTC(),payment.ID); return err}
func (s *PostgresStore) ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error){rows,err:=s.db.QueryContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE user_id=$1`,userID); if err!=nil{return nil, err}; defer rows.Close(); var result []*models.Payment; for rows.Next(){var p models.Payment; if err:=rows.Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); err!=nil{return nil, err}; result=append(result,&p)}; return result, nil}
func (s *PostgresStore) GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error){var w models.Wallet; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, currency, balance_minor FROM wallets WHERE user_id=$1 AND currency=$2`,userID,currency).Scan(&w.ID,&w.UserID,&w.Currency,&w.BalanceMinor); if err!=nil{return nil, err}; return &w, nil}
func (s *PostgresStore) CreateWallet(ctx context.Context, wallet *models.Wallet) error{return s.db.QueryRowContext(ctx,`INSERT INTO wallets (user_id, currency, balance_minor, created_at, updated_at) VALUES ($1,$2,$3,$4,$5) RETURNING id`,wallet.UserID,wallet.Currency,wallet.BalanceMinor,time.Now().UTC(),time.Now().UTC()).Scan(&wallet.ID)}
func (s *PostgresStore) UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error{_,err:=s.db.ExecContext(ctx,`UPDATE wallets SET balance_minor=$1, updated_at=$2 WHERE id=$3`,balanceMinor,time.Now().UTC(),walletID); return err}
func (s *PostgresStore) LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error){var w models.Wallet; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, currency, balance_minor FROM wallets WHERE id=$1 FOR UPDATE`,walletID).Scan(&w.ID,&w.UserID,&w.Currency,&w.BalanceMinor); if err!=nil{return nil, err}; return &w, nil}
func (s *PostgresStore) CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO ledger_entries (wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, created_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)`,entry.WalletID,entry.UserID,entry.Direction,entry.AmountMinor,entry.BalanceAfterMinor,entry.ReferenceType,entry.ReferenceID,entry.IdempotencyKey,time.Now().UTC()); return err}
func (s *PostgresStore) ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error){rows,err:=s.db.QueryContext(ctx,`SELECT id, wallet_id, user_id, direction, amount_minor, balance_after_minor FROM ledger_entries WHERE wallet_id=$1 ORDER BY created_at`,walletID); if err!=nil{return nil, err}; defer rows.Close(); var result []*models.LedgerEntry; for rows.Next(){var e models.LedgerEntry; if err:=rows.Scan(&e.ID,&e.WalletID,&e.UserID,&e.Direction,&e.AmountMinor,&e.BalanceAfterMinor); err!=nil{return nil, err}; result=append(result,&e)}; return result, nil}
func (s *PostgresStore) CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error){var credits,debits sql.NullInt64; err:=s.db.QueryRowContext(ctx,`SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount_minor ELSE 0 END),0), COALESCE(SUM(CASE WHEN direction='debit' THEN amount_minor ELSE 0 END),0) FROM ledger_entries WHERE wallet_id=$1`,walletID).Scan(&credits,&debits); if err!=nil{return 0, err}; return credits.Int64-debits.Int64, nil}
func (s *PostgresStore) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error){rows,err:=s.db.QueryContext(ctx,`SELECT direction, amount_minor, balance_after_minor FROM ledger_entries WHERE wallet_id=$1 ORDER BY created_at, id`,walletID); if err!=nil{return false, err}; defer rows.Close(); var running int64; for rows.Next(){var dir string; var amount,balanceAfter int64; if err:=rows.Scan(&dir,&amount,&balanceAfter); err!=nil{return false, err}; if dir=="credit"{running+=amount} else {running-=amount}; if running!=balanceAfter{return false, nil}}; var stored int64; err=s.db.QueryRowContext(ctx,`SELECT balance_minor FROM wallets WHERE id=$1`,walletID).Scan(&stored); if err!=nil{return false, err}; return running==stored, nil}
func (s *PostgresStore) CreatePayout(ctx context.Context, payout *models.Payout) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO payouts (id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key, created_at, updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10)`,payout.ID,payout.UserID,payout.AmountMinor,payout.Currency,payout.Status,payout.ExternalID,payout.Provider,payout.IdempotencyKey,time.Now().UTC(),time.Now().UTC()); return err}
func (s *PostgresStore) GetPayoutByID(ctx context.Context, id string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE id=$1`,id).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE external_id=$1`,externalID).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE idempotency_key=$1`,key).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *PostgresStore) UpdatePayout(ctx context.Context, payout *models.Payout) error{_,err:=s.db.ExecContext(ctx,`UPDATE payouts SET status=$1, updated_at=$2 WHERE id=$3`,payout.Status,time.Now().UTC(),payout.ID); return err}
func (s *PostgresStore) GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error){var r domain.IdempotencyRecord; err:=s.db.QueryRowContext(ctx,`SELECT key, fingerprint, operation, expires_at FROM idempotency_records WHERE key=$1`,key).Scan(&r.Key,&r.Fingerprint,&r.Operation,&r.ExpiresAt); if err!=nil{return nil, err}; return &r, nil}
func (s *PostgresStore) SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO idempotency_records (key, fingerprint, operation, expires_at, created_at, updated_at) VALUES ($1,$2,$3,$4,$5,$6) ON CONFLICT (key) DO UPDATE SET fingerprint=$2, operation=$3, expires_at=$4, updated_at=$6`,record.Key,record.Fingerprint,record.Operation,record.ExpiresAt,time.Now().UTC(),time.Now().UTC()); return err}
func (s *PostgresStore) DeleteIdempotency(ctx context.Context, key string) error{_,err:=s.db.ExecContext(ctx,`DELETE FROM idempotency_records WHERE key=$1`,key); return err}
func (s *PostgresStore) CleanupIdempotency(ctx context.Context) error{_,err:=s.db.ExecContext(ctx,`DELETE FROM idempotency_records WHERE expires_at<$1`,time.Now().UTC()); return err}
func (s *PostgresStore) HealthCheck(ctx context.Context) error{return s.db.PingContext(ctx)}
func (s *PostgresStore) Close() error{return s.db.Close()}
```

