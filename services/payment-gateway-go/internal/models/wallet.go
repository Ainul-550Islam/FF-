package models
type WalletBalance struct{WalletID int64 `json:"wallet_id"`; BalanceMinor int64 `json:"balance_minor"`}
func CalculateBalance(credits, debits int64) int64 {return credits-debits}
func VerifyLedgerIntegrity(entries []LedgerEntry, walletBalance int64) bool {
    running:=int64(0)
    for _,e:=range entries{
        if e.Direction=="credit"{running+=e.AmountMinor} else {running-=e.AmountMinor}
        if running!=e.BalanceAfterMinor{return false}
    }
    return running==walletBalance
}
