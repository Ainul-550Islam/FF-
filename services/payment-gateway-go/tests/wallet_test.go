package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/models")
func TestWalletBalanceCalculation(t *testing.T){
    credits:=int64(1000); debits:=int64(300)
    balance:=models.CalculateBalance(credits,debits)
    if balance!=700{t.Errorf("expected 700, got %d",balance)}
}
func TestLedgerIntegrity(t *testing.T){
    entries:=[]models.LedgerEntry{
        {Direction:"credit",AmountMinor:1000,BalanceAfterMinor:1000},
        {Direction:"debit",AmountMinor:300,BalanceAfterMinor:700},
    }
    valid:=models.VerifyLedgerIntegrity(entries,700)
    if !valid{t.Error("expected valid ledger integrity")}
    invalidEntries:=[]models.LedgerEntry{
        {Direction:"credit",AmountMinor:1000,BalanceAfterMinor:1000},
        {Direction:"debit",AmountMinor:300,BalanceAfterMinor:600},
    }
    valid=models.VerifyLedgerIntegrity(invalidEntries,700)
    if valid{t.Error("expected invalid ledger integrity")}
}
