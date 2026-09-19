package reconciliation

import (
    "context"
    "fmt"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/domain"
    "github.com/ffarena/payment-gateway-go/internal/models"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/storage"
)

type Service struct {
    store       storage.Store
    metrics     observability.Metrics
    logger      *observability.Logger
    providerMgr map[string]providers.Provider
}

func NewService(store storage.Store, metrics observability.Metrics, logger *observability.Logger, providerMgr map[string]providers.Provider) *Service {
    return &Service{store: store, metrics: metrics, logger: logger, providerMgr: providerMgr}
}

type ReconciliationReport struct {
    Date               time.Time                      `json:"date"`
    TotalPayments      int                            `json:"total_payments"`
    MismatchedAmount   int                            `json:"mismatched_amount"`
    MismatchedStatus   int                            `json:"mismatched_status"`
    MismatchedCurrency int                            `json:"mismatched_currency"`
    DuplicateExternal  int                            `json:"duplicate_external"`
    LedgerMismatches   int                            `json:"ledger_mismatches"`
    MissingProvider    int                            `json:"missing_provider"`
    RefundMismatches   int                            `json:"refund_mismatches"`
    DuplicateCallback  int                            `json:"duplicate_callback"`
    Records            []domain.ReconciliationRecord `json:"records"`
}

type ComparisonResult struct {
    InternalPayment  *models.Payment
    ProviderPayment  *providers.QueryPaymentResponse
    WalletBalance    int64
    LedgerBalance    int64
    LedgerValid      bool
    Mismatches       []string
    ReconciliationRecords []domain.ReconciliationRecord
}

func (s *Service) ReconcilePayment(ctx context.Context, payment *models.Payment, providerAmount int64, providerStatus string) (*domain.ReconciliationRecord, error) {
    if payment.AmountMinor != providerAmount {
        record := &domain.ReconciliationRecord{
            ID:             fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID:      payment.ID,
            Type:           domain.ReconciliationTypeAmountMismatch,
            ExpectedAmount: payment.AmountMinor,
            ActualAmount:   providerAmount,
            Status:         "pending",
            CreatedAt:      time.Now(),
        }
        if s.metrics != nil {
            s.metrics.Increment("reconciliation.amount_mismatch", nil)
        }
        if s.logger != nil {
            s.logger.Info("reconciliation amount mismatch", map[string]interface{}{
                "payment_id": payment.ID,
                "expected":   payment.AmountMinor,
                "actual":     providerAmount,
            })
        }
        return record, nil
    }
    if payment.Status != providerStatus {
        record := &domain.ReconciliationRecord{
            ID:        fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID: payment.ID,
            Type:      domain.ReconciliationTypeStatusMismatch,
            Status:    "pending",
            CreatedAt: time.Now(),
        }
        if s.metrics != nil {
            s.metrics.Increment("reconciliation.status_mismatch", nil)
        }
        return record, nil
    }
    return nil, nil
}

func (s *Service) CompareInternalVsProvider(ctx context.Context, internalPayment *models.Payment) (*ComparisonResult, error) {
    result := &ComparisonResult{
        InternalPayment: internalPayment,
        Mismatches:      []string{},
    }

    // Query provider for current status
    provider, ok := s.providerMgr[internalPayment.Provider]
    if !ok {
        result.Mismatches = append(result.Mismatches, "provider_not_found")
        record := domain.ReconciliationRecord{
            ID:        fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID: internalPayment.ID,
            Type:      "missing_provider_transaction",
            Status:    "pending",
            CreatedAt: time.Now(),
        }
        result.ReconciliationRecords = append(result.ReconciliationRecords, record)
        return result, nil
    }

    queryReq := providers.QueryPaymentRequest{
        ExternalID:        internalPayment.ExternalID,
        ProviderReference: internalPayment.ExternalID,
    }

    providerResp, err := provider.QueryPayment(ctx, queryReq)
    if err != nil {
        result.Mismatches = append(result.Mismatches, fmt.Sprintf("provider_query_failed: %v", err))
        return result, nil
    }

    result.ProviderPayment = providerResp

    // Compare amount
    if providerResp.AmountMinor != 0 && internalPayment.AmountMinor != providerResp.AmountMinor {
        result.Mismatches = append(result.Mismatches, "amount_mismatch")
        record := domain.ReconciliationRecord{
            ID:             fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID:      internalPayment.ID,
            Type:           domain.ReconciliationTypeAmountMismatch,
            ExpectedAmount: internalPayment.AmountMinor,
            ActualAmount:   providerResp.AmountMinor,
            Status:         "pending",
            CreatedAt:      time.Now(),
        }
        result.ReconciliationRecords = append(result.ReconciliationRecords, record)
        if s.metrics != nil {
            s.metrics.Increment("reconciliation.amount_mismatch", nil)
        }
    }

    // Compare currency
    if providerResp.Currency != "" && internalPayment.Currency != providerResp.Currency {
        result.Mismatches = append(result.Mismatches, "currency_mismatch")
        record := domain.ReconciliationRecord{
            ID:        fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID: internalPayment.ID,
            Type:      "currency_mismatch",
            Status:    "pending",
            CreatedAt: time.Now(),
        }
        result.ReconciliationRecords = append(result.ReconciliationRecords, record)
    }

    // Compare status
    mappedStatus := provider.MapProviderStatus(providerResp.Status)
    if internalPayment.Status != mappedStatus {
        // Detect specific mismatch types
        if internalPayment.Status == "succeeded" && mappedStatus == "pending" {
            result.Mismatches = append(result.Mismatches, "internal_success_provider_pending")
        } else if internalPayment.Status == "pending" && mappedStatus == "succeeded" {
            result.Mismatches = append(result.Mismatches, "internal_pending_provider_success")
        } else if internalPayment.Status == "succeeded" && mappedStatus == "failed" {
            result.Mismatches = append(result.Mismatches, "internal_success_provider_failed")
        } else {
            result.Mismatches = append(result.Mismatches, "status_mismatch")
        }

        record := domain.ReconciliationRecord{
            ID:        fmt.Sprintf("rec-%d", time.Now().UnixNano()),
            PaymentID: internalPayment.ID,
            Type:      domain.ReconciliationTypeStatusMismatch,
            Status:    "pending",
            CreatedAt: time.Now(),
        }
        result.ReconciliationRecords = append(result.ReconciliationRecords, record)
        if s.metrics != nil {
            s.metrics.Increment("reconciliation.status_mismatch", nil)
        }
    }

    // Check for duplicate provider reference
    // This would require querying all payments with same provider reference
    // For now, log potential duplicate

    return result, nil
}

func (s *Service) VerifyLedgerIntegrity(ctx context.Context, walletID int64) (bool, error) {
    valid, err := s.store.VerifyLedgerIntegrity(ctx, walletID)
    if err != nil {
        return false, err
    }
    if !valid {
        if s.metrics != nil {
            s.metrics.Increment("reconciliation.ledger_mismatch", nil)
        }
        if s.logger != nil {
            s.logger.Error("ledger integrity failed", map[string]interface{}{"wallet_id": walletID})
        }
    }
    return valid, nil
}

func (s *Service) DetectDuplicateProviderReference(ctx context.Context, providerReference string) (bool, error) {
    // Check if provider reference already exists
    // This would query payments table for duplicate external references
    return false, nil
}

func (s *Service) DetectMissingProviderTransaction(ctx context.Context, payment *models.Payment) (bool, error) {
    // Check if internal success but provider missing
    provider, ok := s.providerMgr[payment.Provider]
    if !ok {
        return true, nil
    }

    queryReq := providers.QueryPaymentRequest{
        ExternalID:        payment.ExternalID,
        ProviderReference: payment.ExternalID,
    }

    _, err := provider.QueryPayment(ctx, queryReq)
    if err != nil {
        // Provider transaction missing
        return true, nil
    }

    return false, nil
}

func (s *Service) GenerateDailyReport(ctx context.Context, date time.Time) (*ReconciliationReport, error) {
    report := &ReconciliationReport{
        Date:    date,
        Records: []domain.ReconciliationRecord{},
    }

    // In real implementation, this would:
    // 1. List all payments for the day
    // 2. For each payment, compare internal vs provider
    // 3. Check wallet ledger integrity
    // 4. Check payout and settlement
    // 5. Generate report

    if s.logger != nil {
        s.logger.Info("daily reconciliation report generated", map[string]interface{}{
            "date": date.Format("2006-01-02"),
            "total_payments": report.TotalPayments,
        })
    }

    if s.metrics != nil {
        s.metrics.Increment("reconciliation.daily_report", nil)
    }

    return report, nil
}

func (s *Service) SafeTransitionCheck(internalStatus, providerStatus string) (bool, string) {
    // Do not automatically mutate money during reconciliation unless explicitly safe business rule exists
    // Only allow safe transitions
    
    // Safe: pending -> succeeded when provider verification is authoritative
    if internalStatus == "pending" && providerStatus == "succeeded" {
        return true, "safe: provider authoritative success"
    }
    
    // Safe: pending -> failed
    if internalStatus == "pending" && providerStatus == "failed" {
        return true, "safe: provider failure"
    }
    
    // Unsafe: succeeded -> pending (never auto-revert success)
    if internalStatus == "succeeded" && providerStatus == "pending" {
        return false, "unsafe: cannot revert succeeded to pending - requires manual review"
    }
    
    // Unsafe: succeeded -> failed (never auto-revert success to failed)
    if internalStatus == "succeeded" && providerStatus == "failed" {
        return false, "unsafe: internal success but provider failed - requires manual review and reconciliation"
    }
    
    // Safe: same status
    if internalStatus == providerStatus {
        return true, "safe: same status"
    }
    
    return false, "unsafe: requires manual review"
}

func (s *Service) ReconcileWallet(ctx context.Context, walletID int64) (bool, error) {
    // Verify ledger integrity
    valid, err := s.VerifyLedgerIntegrity(ctx, walletID)
    if err != nil {
        return false, err
    }
    if !valid {
        if s.logger != nil {
            s.logger.Error("wallet reconciliation failed - ledger mismatch", map[string]interface{}{"wallet_id": walletID})
        }
        return false, nil
    }
    return true, nil
}

func (s *Service) DetectDuplicateCallback(eventID string, processedEvents map[string]bool) bool {
    _, exists := processedEvents[eventID]
    return exists
}

func (s *Service) DetectDuplicateWalletCredit(ledgerEntries []*models.LedgerEntry, paymentID string) bool {
    // Check if same payment already credited wallet
    count := 0
    for _, entry := range ledgerEntries {
        if entry.ReferenceID == paymentID && entry.Direction == "credit" {
            count++
        }
    }
    return count > 1
}
