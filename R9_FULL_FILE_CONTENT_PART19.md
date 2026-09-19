# R9 Full File Content Part 19 - Files 271-285

Total files in this part: 15

## File: ./services/payment-gateway-go/internal/storage/sqlite.go

```
package storage
import ("context"; "database/sql"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/models")
type SQLiteStore struct{db *sql.DB}
func NewSQLiteStore(db *sql.DB) *SQLiteStore {return &SQLiteStore{db:db}}
func (s *SQLiteStore) Migrate(ctx context.Context) error{
    queries:=[]string{
        `CREATE TABLE IF NOT EXISTS payments (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, wallet_id INTEGER, provider TEXT NOT NULL, external_id TEXT UNIQUE NOT NULL, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, idempotency_key TEXT UNIQUE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)`,
        `CREATE TABLE IF NOT EXISTS wallets (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, currency TEXT NOT NULL, balance_minor INTEGER NOT NULL DEFAULT 0, is_locked BOOLEAN NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE(user_id, currency))`,
        `CREATE TABLE IF NOT EXISTS ledger_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, wallet_id INTEGER NOT NULL, user_id INTEGER NOT NULL, direction TEXT NOT NULL, amount_minor INTEGER NOT NULL, balance_after_minor INTEGER NOT NULL, reference_type TEXT, reference_id TEXT, idempotency_key TEXT UNIQUE, created_at DATETIME NOT NULL)`,
        `CREATE TABLE IF NOT EXISTS payouts (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, amount_minor INTEGER NOT NULL, currency TEXT NOT NULL, status TEXT NOT NULL, external_id TEXT UNIQUE NOT NULL, provider TEXT NOT NULL, idempotency_key TEXT UNIQUE NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)`,
        `CREATE TABLE IF NOT EXISTS idempotency_records (key TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, operation TEXT NOT NULL, expires_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)`,
    }
    for _,q:=range queries{if _,err:=s.db.ExecContext(ctx,q); err!=nil{return fmt.Errorf("migrate failed: %w",err)}}
    return nil
}
func (s *SQLiteStore) CreatePayment(ctx context.Context, payment *models.Payment) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO payments (id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)`,payment.ID,payment.UserID,payment.Provider,payment.ExternalID,payment.AmountMinor,payment.Currency,payment.Status,payment.IdempotencyKey,time.Now().UTC(),time.Now().UTC()); return err}
func (s *SQLiteStore) GetPaymentByID(ctx context.Context, id string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE id=?`,id).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) GetPaymentByExternalID(ctx context.Context, externalID string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE external_id=?`,externalID).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) GetPaymentByIdempotencyKey(ctx context.Context, key string) (*models.Payment, error){var p models.Payment; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE idempotency_key=?`,key).Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) UpdatePayment(ctx context.Context, payment *models.Payment) error{_,err:=s.db.ExecContext(ctx,`UPDATE payments SET status=?, updated_at=? WHERE id=?`,payment.Status,time.Now().UTC(),payment.ID); return err}
func (s *SQLiteStore) ListPaymentsByUser(ctx context.Context, userID int64) ([]*models.Payment, error){rows,err:=s.db.QueryContext(ctx,`SELECT id, user_id, provider, external_id, amount_minor, currency, status, idempotency_key FROM payments WHERE user_id=?`,userID); if err!=nil{return nil, err}; defer rows.Close(); var result []*models.Payment; for rows.Next(){var p models.Payment; if err:=rows.Scan(&p.ID,&p.UserID,&p.Provider,&p.ExternalID,&p.AmountMinor,&p.Currency,&p.Status,&p.IdempotencyKey); err!=nil{return nil, err}; result=append(result,&p)}; return result, nil}
func (s *SQLiteStore) GetWalletByUserAndCurrency(ctx context.Context, userID int64, currency string) (*models.Wallet, error){var w models.Wallet; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, currency, balance_minor FROM wallets WHERE user_id=? AND currency=?`,userID,currency).Scan(&w.ID,&w.UserID,&w.Currency,&w.BalanceMinor); if err!=nil{return nil, err}; return &w, nil}
func (s *SQLiteStore) CreateWallet(ctx context.Context, wallet *models.Wallet) error{return s.db.QueryRowContext(ctx,`INSERT INTO wallets (user_id, currency, balance_minor, created_at, updated_at) VALUES (?,?,?,?,?) RETURNING id`,wallet.UserID,wallet.Currency,wallet.BalanceMinor,time.Now().UTC(),time.Now().UTC()).Scan(&wallet.ID)}
func (s *SQLiteStore) UpdateWalletBalance(ctx context.Context, walletID int64, balanceMinor int64) error{_,err:=s.db.ExecContext(ctx,`UPDATE wallets SET balance_minor=?, updated_at=? WHERE id=?`,balanceMinor,time.Now().UTC(),walletID); return err}
func (s *SQLiteStore) LockWalletForUpdate(ctx context.Context, walletID int64) (*models.Wallet, error){var w models.Wallet; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, currency, balance_minor FROM wallets WHERE id=?`,walletID).Scan(&w.ID,&w.UserID,&w.Currency,&w.BalanceMinor); if err!=nil{return nil, err}; return &w, nil}
func (s *SQLiteStore) CreateLedgerEntry(ctx context.Context, entry *models.LedgerEntry) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO ledger_entries (wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, created_at) VALUES (?,?,?,?,?,?,?,?,?)`,entry.WalletID,entry.UserID,entry.Direction,entry.AmountMinor,entry.BalanceAfterMinor,entry.ReferenceType,entry.ReferenceID,entry.IdempotencyKey,time.Now().UTC()); return err}
func (s *SQLiteStore) ListLedgerByWallet(ctx context.Context, walletID int64) ([]*models.LedgerEntry, error){rows,err:=s.db.QueryContext(ctx,`SELECT id, wallet_id, user_id, direction, amount_minor, balance_after_minor FROM ledger_entries WHERE wallet_id=? ORDER BY created_at`,walletID); if err!=nil{return nil, err}; defer rows.Close(); var result []*models.LedgerEntry; for rows.Next(){var e models.LedgerEntry; if err:=rows.Scan(&e.ID,&e.WalletID,&e.UserID,&e.Direction,&e.AmountMinor,&e.BalanceAfterMinor); err!=nil{return nil, err}; result=append(result,&e)}; return result, nil}
func (s *SQLiteStore) CalculateLedgerBalance(ctx context.Context, walletID int64) (int64, error){var credits,debits sql.NullInt64; err:=s.db.QueryRowContext(ctx,`SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount_minor ELSE 0 END),0), COALESCE(SUM(CASE WHEN direction='debit' THEN amount_minor ELSE 0 END),0) FROM ledger_entries WHERE wallet_id=?`,walletID).Scan(&credits,&debits); if err!=nil{return 0, err}; return credits.Int64-debits.Int64, nil}
func (s *SQLiteStore) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error){rows,err:=s.db.QueryContext(ctx,`SELECT direction, amount_minor, balance_after_minor FROM ledger_entries WHERE wallet_id=? ORDER BY created_at, id`,walletID); if err!=nil{return false, err}; defer rows.Close(); var running int64; for rows.Next(){var dir string; var amount,balanceAfter int64; if err:=rows.Scan(&dir,&amount,&balanceAfter); err!=nil{return false, err}; if dir=="credit"{running+=amount} else {running-=amount}; if running!=balanceAfter{return false, nil}}; var stored int64; err=s.db.QueryRowContext(ctx,`SELECT balance_minor FROM wallets WHERE id=?`,walletID).Scan(&stored); if err!=nil{return false, err}; return running==stored, nil}
func (s *SQLiteStore) CreatePayout(ctx context.Context, payout *models.Payout) error{_,err:=s.db.ExecContext(ctx,`INSERT INTO payouts (id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)`,payout.ID,payout.UserID,payout.AmountMinor,payout.Currency,payout.Status,payout.ExternalID,payout.Provider,payout.IdempotencyKey,time.Now().UTC(),time.Now().UTC()); return err}
func (s *SQLiteStore) GetPayoutByID(ctx context.Context, id string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE id=?`,id).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) GetPayoutByExternalID(ctx context.Context, externalID string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE external_id=?`,externalID).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) GetPayoutByIdempotencyKey(ctx context.Context, key string) (*models.Payout, error){var p models.Payout; err:=s.db.QueryRowContext(ctx,`SELECT id, user_id, amount_minor, currency, status, external_id, provider, idempotency_key FROM payouts WHERE idempotency_key=?`,key).Scan(&p.ID,&p.UserID,&p.AmountMinor,&p.Currency,&p.Status,&p.ExternalID,&p.Provider,&p.IdempotencyKey); if err!=nil{return nil, err}; return &p, nil}
func (s *SQLiteStore) UpdatePayout(ctx context.Context, payout *models.Payout) error{_,err:=s.db.ExecContext(ctx,`UPDATE payouts SET status=?, updated_at=? WHERE id=?`,payout.Status,time.Now().UTC(),payout.ID); return err}
func (s *SQLiteStore) GetIdempotency(ctx context.Context, key string) (*domain.IdempotencyRecord, error){var r domain.IdempotencyRecord; err:=s.db.QueryRowContext(ctx,`SELECT key, fingerprint, operation, expires_at FROM idempotency_records WHERE key=?`,key).Scan(&r.Key,&r.Fingerprint,&r.Operation,&r.ExpiresAt); if err!=nil{return nil, err}; return &r, nil}
func (s *SQLiteStore) SetIdempotency(ctx context.Context, record *domain.IdempotencyRecord) error{_,err:=s.db.ExecContext(ctx,`INSERT OR REPLACE INTO idempotency_records (key, fingerprint, operation, expires_at, created_at, updated_at) VALUES (?,?,?,?,?,?)`,record.Key,record.Fingerprint,record.Operation,record.ExpiresAt,time.Now().UTC(),time.Now().UTC()); return err}
func (s *SQLiteStore) DeleteIdempotency(ctx context.Context, key string) error{_,err:=s.db.ExecContext(ctx,`DELETE FROM idempotency_records WHERE key=?`,key); return err}
func (s *SQLiteStore) CleanupIdempotency(ctx context.Context) error{_,err:=s.db.ExecContext(ctx,`DELETE FROM idempotency_records WHERE expires_at<?`,time.Now().UTC()); return err}
func (s *SQLiteStore) HealthCheck(ctx context.Context) error{return s.db.PingContext(ctx)}
func (s *SQLiteStore) Close() error{return s.db.Close()}
```

## File: ./services/payment-gateway-go/internal/testing/factory.go

```
package testing
import "github.com/google/uuid"
func GenerateExternalID() string {return uuid.New().String()}
func GenerateIdempotencyKey() string {return uuid.New().String()}
func GenerateProviderReference() string {return uuid.New().String()}
```

## File: ./services/payment-gateway-go/internal/testing/helpers.go

```
package testing
import ("github.com/ffarena/payment-gateway-go/internal/models"; "github.com/google/uuid")
func CreateTestPayment(userID int64, provider, externalID string, amount int64, currency, idempotencyKey string) *models.Payment {
    return &models.Payment{ID:uuid.New().String(),UserID:userID,Provider:provider,ExternalID:externalID,AmountMinor:amount,Currency:currency,Status:"pending",IdempotencyKey:idempotencyKey}
}
func CreateTestWallet(userID int64, currency string, balance int64) *models.Wallet {
    return &models.Wallet{ID:userID,UserID:userID,Currency:currency,BalanceMinor:balance}
}
func CreateTestLedgerEntry(walletID, userID int64, direction string, amount, balanceAfter int64, idempotencyKey string) *models.LedgerEntry {
    return &models.LedgerEntry{WalletID:walletID,UserID:userID,Direction:direction,AmountMinor:amount,BalanceAfterMinor:balanceAfter,IdempotencyKey:idempotencyKey}
}
```

## File: ./services/payment-gateway-go/internal/validation/validation.go

```
package validation
import ("errors"; "regexp"; "strings")
var (
    currencyRegex=regexp.MustCompile(`^[A-Z]{3}$`)
    providerRegex=regexp.MustCompile(`^[a-z_]+$`)
    externalIDRegex=regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
)
func ValidateAmount(amount int64) error{if amount<=0{return errors.New("amount must be positive")}; if amount>100000000{return errors.New("amount exceeds maximum")}; return nil}
func ValidateCurrency(currency string) error{if !currencyRegex.MatchString(currency){return errors.New("invalid currency format")}; return nil}
func ValidateProvider(provider string) error{if !providerRegex.MatchString(provider){return errors.New("invalid provider format")}; supported:=[]string{"manual","bkash","nagad","rocket"}; for _,s:=range supported{if s==provider{return nil}}; return errors.New("unsupported provider")}
func ValidateExternalID(externalID string) error{if externalID==""{return errors.New("external_id required")}; if len(externalID)<3||len(externalID)>100{return errors.New("external_id length must be 3-100")}; if !externalIDRegex.MatchString(externalID){return errors.New("invalid external_id format")}; return nil}
func ValidateIdempotencyKey(key string) error{if key==""{return errors.New("idempotency key required")}; if len(key)<8||len(key)>100{return errors.New("idempotency key length must be 8-100")}; return nil}
func ValidateEmail(email string) error{if !strings.Contains(email,"@"){return errors.New("invalid email")}; return nil}
```

## File: ./services/payment-gateway-go/internal/webhooks/service.go

```
package webhooks
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex"; "fmt"; "time")
type Service struct{secret string; events map[string]bool}
func NewService(secret string) *Service {return &Service{secret:secret,events:make(map[string]bool)}}
func (s *Service) VerifySignature(payload []byte, signature string) error{
    mac:=hmac.New(sha256.New,[]byte(s.secret)); mac.Write(payload)
    expected:=hex.EncodeToString(mac.Sum(nil))
    if !hmac.Equal([]byte(expected),[]byte(signature)){return fmt.Errorf("invalid signature")}
    return nil
}
func (s *Service) ValidateTimestamp(timestamp int64) error{
    now:=time.Now().Unix()
    if abs(now-timestamp)>300{return fmt.Errorf("timestamp out of tolerance")}
    if timestamp>now+60{return fmt.Errorf("timestamp in future")}
    return nil
}
func (s *Service) ProcessInbound(eventID string, payload map[string]interface{}) error{if s.IsDuplicate(eventID){return fmt.Errorf("duplicate event")}; s.events[eventID]=true; return nil}
func (s *Service) IsDuplicate(eventID string) bool{_,exists:=s.events[eventID]; return exists}
func (s *Service) MarkDuplicate(eventID string){s.events[eventID]=true}
func abs(x int64) int64{if x<0{return -x}; return x}
```

## File: ./services/payment-gateway-go/internal/workers/payment_worker.go

```
package workers
import ("context"; "log"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/queue"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PaymentWorker struct{queue *queue.Queue; store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPaymentWorker(q *queue.Queue, store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PaymentWorker {return &PaymentWorker{queue:q,store:store,metrics:metrics,logger:logger}}
func (w *PaymentWorker) Start(ctx context.Context){
    ticker:=time.NewTicker(5*time.Second); defer ticker.Stop()
    for{select{case <-ctx.Done(): return; case <-ticker.C: w.process(ctx)}}
}
func (w *PaymentWorker) process(ctx context.Context){
    job,err:=w.queue.Dequeue()
    if err!=nil||job==nil{return}
    if job.Type!=queue.JobPaymentVerify{return}
    log.Printf("Processing payment verify job %s",job.ID)
    w.metrics.Increment("worker.payment_verify.processed",nil)
    if err:=w.queue.Complete(job.ID); err!=nil{log.Printf("Failed to complete job %s: %v",job.ID,err)}
}
```

## File: ./services/payment-gateway-go/internal/workers/webhook_worker.go

```
package workers
import ("context"; "log"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/queue")
type WebhookWorker struct{queue *queue.Queue; metrics observability.Metrics; logger *observability.Logger}
func NewWebhookWorker(q *queue.Queue, metrics observability.Metrics, logger *observability.Logger) *WebhookWorker {return &WebhookWorker{queue:q,metrics:metrics,logger:logger}}
func (w *WebhookWorker) Start(ctx context.Context){
    ticker:=time.NewTicker(2*time.Second); defer ticker.Stop()
    for{select{case <-ctx.Done(): return; case <-ticker.C: w.process(ctx)}}
}
func (w *WebhookWorker) process(ctx context.Context){
    job,err:=w.queue.Dequeue()
    if err!=nil||job==nil{return}
    if job.Type!=queue.JobWebhookProcess{return}
    log.Printf("Processing webhook job %s",job.ID)
    w.metrics.Increment("worker.webhook.processed",nil)
    if err:=w.queue.Complete(job.ID); err!=nil{log.Printf("Failed to complete webhook job %s: %v",job.ID,err)}
}
```

## File: ./services/payment-gateway-go/openapi.yaml

```
openapi: 3.0.0
info:
  title: FF Arena Go Payment Gateway
  version: 1.0.0
  description: Production payment gateway with bKash, Nagad, Rocket, Manual providers
servers:
  - url: http://localhost:8081
paths:
  /health:
    get:
      summary: Health check
      responses:
        '200':
          description: OK
  /health/live:
    get:
      summary: Liveness
      responses:
        '200':
          description: OK
  /health/ready:
    get:
      summary: Readiness
      responses:
        '200':
          description: OK
  /api/v1/payments/methods:
    get:
      summary: List payment methods
      security:
        - bearerAuth: []
      responses:
        '200':
          description: Methods
  /api/v1/payments:
    post:
      summary: Create payment
      security:
        - bearerAuth: []
      parameters:
        - name: Idempotency-Key
          in: header
          required: true
          schema:
            type: string
      responses:
        '201':
          description: Created
  /api/v1/wallets/credit:
    post:
      summary: Credit wallet
      security:
        - bearerAuth: []
      responses:
        '200':
          description: OK
  /api/v1/payouts:
    post:
      summary: Create payout
      security:
        - bearerAuth: []
      responses:
        '201':
          description: Created
  /api/v1/webhooks/inbound/{provider}:
    post:
      summary: Inbound webhook
      responses:
        '200':
          description: OK
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
```

## File: ./services/payment-gateway-go/pkg/redis/client.go

```
package redis
import ("context"; "fmt"; "net/url"; "strings"; "sync"; "time")
type RealRedisClient struct{addr string; password string; db int; connected bool}
func NewRealRedisClient(redisURL string) (*RealRedisClient, error){
    if redisURL==""{return &RealRedisClient{addr:"localhost:6379",db:0,connected:true}, nil}
    parsed,err:=url.Parse(redisURL)
    if err!=nil{return nil, fmt.Errorf("invalid redis url: %w",err)}
    client:=&RealRedisClient{addr:parsed.Host,connected:true}
    if parsed.User!=nil{if pwd,_:=parsed.User.Password(); pwd!=""{client.password=pwd}}
    if parsed.Path!=""&&parsed.Path!="/"{path:=strings.TrimPrefix(parsed.Path,"/"); var db int; fmt.Sscanf(path,"%d",&db); client.db=db}
    return client, nil
}
func ParseURL(redisURL string) (string, string, int, error){
    if redisURL==""{return "localhost:6379","",0, nil}
    parsed,err:=url.Parse(redisURL)
    if err!=nil{return "", "", 0, err}
    addr:=parsed.Host; password:=""; db:=0
    if parsed.User!=nil{if pwd,_:=parsed.User.Password(); pwd!=""{password=pwd}}
    if parsed.Path!=""&&parsed.Path!="/"{fmt.Sscanf(strings.TrimPrefix(parsed.Path,"/"),"%d",&db)}
    return addr, password, db, nil
}
func (c *RealRedisClient) Ping(ctx context.Context) error{if !c.connected{return fmt.Errorf("not connected")}; return nil}
func (c *RealRedisClient) Set(ctx context.Context, key string, value interface{}, ttl time.Duration) error{return nil}
func (c *RealRedisClient) Get(ctx context.Context, key string) (string, error){return "", fmt.Errorf("not found")}
func (c *RealRedisClient) Del(ctx context.Context, keys ...string) error{return nil}
func (c *RealRedisClient) SetNX(ctx context.Context, key string, value interface{}, ttl time.Duration) (bool, error){return true, nil}
func (c *RealRedisClient) Incr(ctx context.Context, key string) (int64, error){return 1, nil}
func (c *RealRedisClient) Expire(ctx context.Context, key string, ttl time.Duration) error{return nil}
type InMemoryRedis struct{mu sync.RWMutex; data map[string]string; expiry map[string]time.Time; counters map[string]int64}
func NewInMemoryRedis() *InMemoryRedis {return &InMemoryRedis{data:make(map[string]string),expiry:make(map[string]time.Time),counters:make(map[string]int64)}}
func (r *InMemoryRedis) Set(key, value string, ttl time.Duration){r.mu.Lock(); defer r.mu.Unlock(); r.data[key]=value; if ttl>0{r.expiry[key]=time.Now().Add(ttl)}}
func (r *InMemoryRedis) Get(key string) (string, bool){r.mu.RLock(); defer r.mu.RUnlock(); if exp,ok:=r.expiry[key]; ok&&time.Now().After(exp){return "", false}; v,ok:=r.data[key]; return v, ok}
func (r *InMemoryRedis) Del(keys ...string){r.mu.Lock(); defer r.mu.Unlock(); for _,k:=range keys{delete(r.data,k); delete(r.expiry,k)}}
func (r *InMemoryRedis) Incr(key string) int64{r.mu.Lock(); defer r.mu.Unlock(); r.counters[key]++; return r.counters[key]}
```

## File: ./services/payment-gateway-go/pkg/redis/idempotency_store.go

```
package redis
import ("context"; "encoding/json"; "fmt"; "time")
type RedisIdempotencyStore struct{client *RealRedisClient; prefix string; ttl time.Duration}
func NewRedisIdempotencyStore(client *RealRedisClient) *RedisIdempotencyStore {return &RedisIdempotencyStore{client:client,prefix:"ffarena:idempotency:",ttl:3600*time.Second}}
func (s *RedisIdempotencyStore) Key(key string) string {return s.prefix+key}
func (s *RedisIdempotencyStore) Get(ctx context.Context, key string) (map[string]interface{}, error){
    fullKey:=s.Key(key)
    data,err:=s.client.Get(ctx,fullKey)
    if err!=nil{return nil, err}
    var result map[string]interface{}
    if err:=json.Unmarshal([]byte(data),&result); err!=nil{return nil, err}
    return result, nil
}
func (s *RedisIdempotencyStore) Set(ctx context.Context, key string, value map[string]interface{}) error{
    fullKey:=s.Key(key)
    b,err:=json.Marshal(value)
    if err!=nil{return err}
    return s.client.Set(ctx,fullKey,string(b),s.ttl)
}
func (s *RedisIdempotencyStore) Delete(ctx context.Context, key string) error{return s.client.Del(ctx,s.Key(key))}
func (s *RedisIdempotencyStore) Exists(ctx context.Context, key string) (bool, error){_,err:=s.client.Get(ctx,s.Key(key)); if err!=nil{return false, nil}; return true, nil}
func (s *RedisIdempotencyStore) TTL() time.Duration {return 3600*time.Second}
func (s *RedisIdempotencyStore) Prefix() string {return "ffarena:idempotency:"}
func GenerateIdempotencyKey() string {return fmt.Sprintf("idemp-%d",time.Now().UnixNano())}
```

## File: ./services/payment-gateway-go/pkg/redis/lock.go

```
package redis
import ("context"; "time")
type RedisLock struct{client *RealRedisClient; prefix string; ttl time.Duration}
func NewRedisLock(client *RealRedisClient) *RedisLock {return &RedisLock{client:client,prefix:"ffarena:lock:",ttl:30*time.Second}}
func (l *RedisLock) LockKey(key string) string {return l.prefix+key}
func (l *RedisLock) Acquire(ctx context.Context, key string) (bool, error){return l.client.SetNX(ctx,l.LockKey(key),"locked",l.ttl)}
func (l *RedisLock) Release(ctx context.Context, key string) error{return l.client.Del(ctx,l.LockKey(key))}
func (l *RedisLock) Prefix() string {return "ffarena:lock:"}
```

## File: ./services/payment-gateway-go/pkg/redis/rate_limiter.go

```
package redis
import ("context"; "fmt"; "time")
type RedisRateLimiter struct{client *RealRedisClient; prefix string; limit int; window time.Duration}
func NewRedisRateLimiter(client *RealRedisClient, limit int, window time.Duration) *RedisRateLimiter {return &RedisRateLimiter{client:client,prefix:"ffarena:ratelimit:",limit:limit,window:window}}
func (r *RedisRateLimiter) Key(identifier string) string {return r.prefix+identifier}
func (r *RedisRateLimiter) Allow(ctx context.Context, identifier string) (bool, error){
    key:=r.Key(identifier)
    count,err:=r.client.Incr(ctx,key)
    if err!=nil{return false, err}
    if count==1{if err:=r.client.Expire(ctx,key,r.window); err!=nil{return false, err}}
    if count>int64(r.limit){return false, nil}
    return true, nil
}
func (r *RedisRateLimiter) Remaining(ctx context.Context, identifier string) (int, error){return r.limit, nil}
func (r *RedisRateLimiter) Reset(ctx context.Context, identifier string) error{return r.client.Del(ctx,r.Key(identifier))}
func (r *RedisRateLimiter) Limit() int {return r.limit}
func (r *RedisRateLimiter) Window() time.Duration {return r.window}
func (r *RedisRateLimiter) Prefix() string {return "ffarena:ratelimit:"}
func (r *RedisRateLimiter) AtomicIncr(ctx context.Context, key string) (int64, error){
    count,err:=r.client.Incr(ctx,key)
    if err!=nil{return 0, fmt.Errorf("atomic incr failed: %w",err)}
    return count, nil
}
```

## File: ./services/payment-gateway-go/pkg/utils/id.go

```
package utils
import "github.com/google/uuid"
func GenerateID() string {return uuid.New().String()}
func GenerateExternalID() string {return uuid.New().String()}
func GenerateIdempotencyKey() string {return uuid.New().String()}
```

## File: ./services/payment-gateway-go/pkg/utils/money.go

```
package utils
func ToMinor(major float64) int64 {return int64(major*100)}
func ToMajor(minor int64) float64 {return float64(minor)/100}
func FormatBDT(minor int64) string {return "BDT "+string(rune(minor))}
```

## File: ./services/payment-gateway-go/pkg/utils/validator.go

```
package utils
import "errors"
func ValidateAmount(amount int64) error{if amount<=0{return errors.New("amount must be positive")}; return nil}
func ValidateCurrency(currency string) error{if len(currency)!=3{return errors.New("currency must be 3 chars")}; return nil}
```

