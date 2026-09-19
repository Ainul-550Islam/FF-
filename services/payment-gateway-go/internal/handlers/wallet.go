package handlers
import ("encoding/json"; "net/http"; "sync"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type WalletHandler struct{store storage.Store; metrics observability.Metrics; mu sync.RWMutex; wallets map[string]*models.Wallet; ledger map[int64][]*models.LedgerEntry}
func NewWalletHandler(store storage.Store, metrics observability.Metrics) *WalletHandler {return &WalletHandler{store:store,metrics:metrics,wallets:make(map[string]*models.Wallet),ledger:make(map[int64][]*models.LedgerEntry)}}
type CreditRequest struct{UserID int64 `json:"user_id"`; AmountMinor int64 `json:"amount_minor"`; Currency string `json:"currency"`; ReferenceType string `json:"reference_type"`; ReferenceID string `json:"reference_id"`; IdempotencyKey string `json:"idempotency_key"`}
func (h *WalletHandler) Credit(w http.ResponseWriter, r *http.Request){
    var req CreditRequest
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    if req.AmountMinor<=0{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"amount must be positive"}); return}
    key:=req.IdempotencyKey; if key==""{key=uuid.New().String()}
    h.mu.Lock(); defer h.mu.Unlock()
    walletKey:=string(rune(req.UserID))+":"+req.Currency
    wallet,ok:=h.wallets[walletKey]
    if !ok{wallet=&models.Wallet{ID:int64(len(h.wallets)+1),UserID:req.UserID,Currency:req.Currency,BalanceMinor:0}; h.wallets[walletKey]=wallet}
    for _,entry:=range h.ledger[wallet.ID]{if entry.IdempotencyKey==key{w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry); return}}
    newBalance:=wallet.BalanceMinor+req.AmountMinor
    entry:=&models.LedgerEntry{ID:int64(len(h.ledger[wallet.ID])+1),WalletID:wallet.ID,UserID:req.UserID,Direction:"credit",AmountMinor:req.AmountMinor,BalanceAfterMinor:newBalance,ReferenceType:req.ReferenceType,ReferenceID:req.ReferenceID,IdempotencyKey:key}
    h.ledger[wallet.ID]=append(h.ledger[wallet.ID],entry)
    wallet.BalanceMinor=newBalance
    h.metrics.Increment("wallet.credit",map[string]string{"currency":req.Currency})
    w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry)
}
func (h *WalletHandler) Debit(w http.ResponseWriter, r *http.Request){
    var req CreditRequest
    if err:=json.NewDecoder(r.Body).Decode(&req); err!=nil{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"invalid_request"}); return}
    if req.AmountMinor<=0{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"amount must be positive"}); return}
    key:=req.IdempotencyKey; if key==""{key=uuid.New().String()}
    h.mu.Lock(); defer h.mu.Unlock()
    walletKey:=string(rune(req.UserID))+":"+req.Currency
    wallet,ok:=h.wallets[walletKey]
    if !ok{w.WriteHeader(404); json.NewEncoder(w).Encode(map[string]string{"error":"wallet_not_found"}); return}
    if wallet.BalanceMinor<req.AmountMinor{w.WriteHeader(400); json.NewEncoder(w).Encode(map[string]string{"error":"insufficient_funds"}); return}
    for _,entry:=range h.ledger[wallet.ID]{if entry.IdempotencyKey==key{w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry); return}}
    newBalance:=wallet.BalanceMinor-req.AmountMinor
    entry:=&models.LedgerEntry{ID:int64(len(h.ledger[wallet.ID])+1),WalletID:wallet.ID,UserID:req.UserID,Direction:"debit",AmountMinor:req.AmountMinor,BalanceAfterMinor:newBalance,ReferenceType:req.ReferenceType,ReferenceID:req.ReferenceID,IdempotencyKey:key}
    h.ledger[wallet.ID]=append(h.ledger[wallet.ID],entry)
    wallet.BalanceMinor=newBalance
    h.metrics.Increment("wallet.debit",map[string]string{"currency":req.Currency})
    w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(entry)
}
func (h *WalletHandler) GetBalance(w http.ResponseWriter, r *http.Request){w.Header().Set("Content-Type","application/json"); json.NewEncoder(w).Encode(map[string]interface{}{"balance_minor":0,"currency":"BDT"})}
