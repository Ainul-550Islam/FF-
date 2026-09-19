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
