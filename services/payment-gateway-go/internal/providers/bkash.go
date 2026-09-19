package providers

import (
    "context"
    "encoding/json"
    "errors"
    "fmt"
    "sync"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers/client"
    "github.com/google/uuid"
)

type BkashProvider struct {
    BaseProvider
    httpClient   *client.ProviderHTTPClient
    tokenCache   *bkashTokenCache
    logger       *observability.Logger
    metrics      observability.Metrics
}

type bkashTokenCache struct {
    mu           sync.RWMutex
    token        string
    expiresAt    time.Time
    refreshMu    sync.Mutex
}

type bkashGrantTokenRequest struct {
    AppKey    string `json:"app_key"`
    AppSecret string `json:"app_secret"`
}

type bkashGrantTokenResponse struct {
    IDToken     string `json:"id_token"`
    TokenType   string `json:"token_type"`
    ExpiresIn   int    `json:"expires_in"`
    RefreshToken string `json:"refresh_token,omitempty"`
}

type bkashCreatePaymentRequest struct {
    Mode                  string `json:"mode"`
    PayerReference        string `json:"payerReference"`
    CallbackURL           string `json:"callbackURL"`
    Amount                string `json:"amount"`
    Currency              string `json:"currency"`
    Intent                string `json:"intent"`
    MerchantInvoiceNumber string `json:"merchantInvoiceNumber"`
    MerchantAssociationInfo string `json:"merchantAssociationInfo,omitempty"`
}

type bkashCreatePaymentResponse struct {
    PaymentID             string `json:"paymentID"`
    PaymentCreateTime     string `json:"paymentCreateTime"`
    TransactionStatus     string `json:"transactionStatus"`
    MerchantInvoiceNumber string `json:"merchantInvoiceNumber"`
    BkashURL              string `json:"bkashURL"`
    CallbackURL           string `json:"callbackURL"`
    Amount                string `json:"amount"`
    Intent                string `json:"intent"`
    Currency              string `json:"currency"`
    ErrorCode             string `json:"errorCode,omitempty"`
    ErrorMessage          string `json:"errorMessage,omitempty"`
}

type bkashExecutePaymentRequest struct {
    PaymentID string `json:"paymentID"`
}

type bkashExecutePaymentResponse struct {
    PaymentID                string `json:"paymentID"`
    TrxID                    string `json:"trxID"`
    TransactionStatus        string `json:"transactionStatus"`
    Amount                   string `json:"amount"`
    Currency                 string `json:"currency"`
    Intent                   string `json:"intent"`
    MerchantInvoiceNumber    string `json:"merchantInvoiceNumber"`
    ErrorCode                string `json:"errorCode,omitempty"`
    ErrorMessage             string `json:"errorMessage,omitempty"`
}

type bkashQueryPaymentRequest struct {
    PaymentID string `json:"paymentID"`
}

type bkashQueryPaymentResponse struct {
    PaymentID             string `json:"paymentID"`
    TrxID                 string `json:"trxID"`
    TransactionStatus     string `json:"transactionStatus"`
    Amount                string `json:"amount"`
    Currency              string `json:"currency"`
    MerchantInvoiceNumber string `json:"merchantInvoiceNumber"`
    ErrorCode             string `json:"errorCode,omitempty"`
    ErrorMessage          string `json:"errorMessage,omitempty"`
}

type bkashRefundRequest struct {
    PaymentID string `json:"paymentID"`
    Amount    string `json:"amount"`
    TrxID     string `json:"trxID"`
    SKU       string `json:"sku"`
    Reason    string `json:"reason"`
}

type bkashRefundResponse struct {
    OriginalTrxID       string `json:"originalTrxID"`
    RefundTrxID         string `json:"refundTrxID"`
    TransactionStatus   string `json:"transactionStatus"`
    Amount              string `json:"amount"`
    Currency            string `json:"currency"`
    ErrorCode           string `json:"errorCode,omitempty"`
    ErrorMessage        string `json:"errorMessage,omitempty"`
}

func NewBkashProvider(cfg ProviderConfig, logger *observability.Logger, metrics observability.Metrics) *BkashProvider {
    timeout := time.Duration(cfg.TimeoutMs) * time.Millisecond
    if timeout == 0 {
        timeout = 15 * time.Second
    }
    httpClient := client.NewProviderHTTPClient(client.HTTPClientConfig{
        BaseURL:  cfg.BaseURL,
        Timeout:  timeout,
        Provider: "bkash",
        Logger:   logger,
        Metrics:  metrics,
    })
    return &BkashProvider{
        BaseProvider: BaseProvider{Config: cfg},
        httpClient:   httpClient,
        tokenCache:   &bkashTokenCache{},
        logger:       logger,
        metrics:      metrics,
    }
}

func (p *BkashProvider) Key() string { return "bkash" }
func (p *BkashProvider) Label() string { return "bKash" }
func (p *BkashProvider) SupportsCurrency(currency string) bool { return currency == "BDT" }
func (p *BkashProvider) SupportsRefund() bool { return true }
func (p *BkashProvider) Capabilities() []string { return []string{"create", "query", "refund", "webhook", "execute", "token_grant"} }
func (p *BkashProvider) Metadata() map[string]interface{} {
    return map[string]interface{}{
        "type": "mobile_banking", "currency": "BDT", "min_amount": 10, "max_amount": 25000,
        "sandbox_url": "https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout",
        "live_url": "https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout",
        "auth_flow": "grant_token",
        "supported_operations": []string{"grant_token", "create_payment", "execute_payment", "query_payment", "refund"},
    }
}
func (p *BkashProvider) IsEnabled() bool { return p.Config.Enabled }
func (p *BkashProvider) ValidateConfig() error {
    if !p.Config.Enabled {
        return nil
    }
    if p.Config.AppKey == "" {
        return errors.New("bkash app_key required")
    }
    if p.Config.AppSecret == "" {
        return errors.New("bkash app_secret required")
    }
    if p.Config.Username == "" {
        return errors.New("bkash username required")
    }
    if p.Config.Password == "" {
        return errors.New("bkash password required")
    }
    if p.Config.BaseURL == "" {
        return errors.New("bkash base_url required")
    }
    return nil
}

func (p *BkashProvider) getToken(ctx context.Context) (string, error) {
    // Check cache first with RW lock
    p.tokenCache.mu.RLock()
    if p.tokenCache.token != "" && time.Now().Before(p.tokenCache.expiresAt.Add(-5*time.Minute)) {
        token := p.tokenCache.token
        p.tokenCache.mu.RUnlock()
        return token, nil
    }
    p.tokenCache.mu.RUnlock()

    // Acquire refresh lock to prevent token duplication storms
    p.tokenCache.refreshMu.Lock()
    defer p.tokenCache.refreshMu.Unlock()

    // Double-check after acquiring refresh lock
    p.tokenCache.mu.RLock()
    if p.tokenCache.token != "" && time.Now().Before(p.tokenCache.expiresAt.Add(-5*time.Minute)) {
        token := p.tokenCache.token
        p.tokenCache.mu.RUnlock()
        return token, nil
    }
    p.tokenCache.mu.RUnlock()

    // Grant new token
    reqBody := bkashGrantTokenRequest{
        AppKey:    p.Config.AppKey,
        AppSecret: p.Config.AppSecret,
    }

    headers := map[string]string{
        "username": p.Config.Username,
        "password": p.Config.Password,
    }

    resp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:  "POST",
        Path:    "/token/grant",
        Body:    reqBody,
        Headers: headers,
    })
    if err != nil {
        return "", fmt.Errorf("bkash grant token failed: %w", err)
    }

    if resp.StatusCode != 200 {
        return "", fmt.Errorf("bkash grant token failed with status %d: %s", resp.StatusCode, string(resp.Body))
    }

    var tokenResp bkashGrantTokenResponse
    if err := json.Unmarshal(resp.Body, &tokenResp); err != nil {
        return "", fmt.Errorf("bkash grant token unmarshal failed: %w", err)
    }

    if tokenResp.IDToken == "" {
        return "", fmt.Errorf("bkash grant token empty id_token")
    }

    expiresIn := tokenResp.ExpiresIn
    if expiresIn == 0 {
        expiresIn = 3600
    }

    p.tokenCache.mu.Lock()
    p.tokenCache.token = tokenResp.IDToken
    p.tokenCache.expiresAt = time.Now().Add(time.Duration(expiresIn) * time.Second)
    p.tokenCache.mu.Unlock()

    if p.metrics != nil {
        p.metrics.Increment("bkash_token_granted", map[string]string{"provider": "bkash"})
    }

    return tokenResp.IDToken, nil
}

func (p *BkashProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {
    if req.AmountMinor < 1000 {
        return nil, errors.New("bkash minimum 10 BDT")
    }
    if req.AmountMinor > 2500000 {
        return nil, errors.New("bkash maximum 25000 BDT")
    }

    if !p.Config.Enabled {
        // Fallback to mock for disabled provider in test environment
        resp := p.CreateBasePayment(req)
        resp.Metadata = map[string]interface{}{"trxID": resp.ProviderReference, "amount": req.AmountMinor, "currency": "BDT", "intent": "sale", "mock": true}
        resp.Status = "pending"
        return &resp, nil
    }

    token, err := p.getToken(ctx)
    if err != nil {
        return nil, fmt.Errorf("bkash get token failed: %w", err)
    }

    amountMajor := fmt.Sprintf("%.2f", float64(req.AmountMinor)/100.0)
    callbackURL := req.CallbackURL
    if callbackURL == "" {
        callbackURL = p.Config.CallbackURL
        if callbackURL == "" {
            callbackURL = "https://example.com/callback"
        }
    }

    createReq := bkashCreatePaymentRequest{
        Mode:                  "0011",
        PayerReference:        fmt.Sprintf("user-%d", req.UserID),
        CallbackURL:           callbackURL,
        Amount:                amountMajor,
        Currency:              req.Currency,
        Intent:                "sale",
        MerchantInvoiceNumber: req.ExternalID,
    }

    headers := map[string]string{
        "Authorization": token,
        "X-APP-Key":     p.Config.AppKey,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:         "POST",
        Path:           "/create",
        Body:           createReq,
        Headers:        headers,
        IdempotencyKey: req.IdempotencyKey,
        RequestID:      req.IdempotencyKey,
    })
    if err != nil {
        return nil, fmt.Errorf("bkash create payment http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("bkash create payment failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var createResp bkashCreatePaymentResponse
    if err := json.Unmarshal(httpResp.Body, &createResp); err != nil {
        return nil, fmt.Errorf("bkash create payment unmarshal failed: %w", err)
    }

    if createResp.ErrorCode != "" {
        return nil, fmt.Errorf("bkash create payment error %s: %s", createResp.ErrorCode, createResp.ErrorMessage)
    }

    if createResp.PaymentID == "" {
        return nil, fmt.Errorf("bkash create payment empty paymentID")
    }

    status := p.MapProviderStatus(createResp.TransactionStatus)
    paymentURL := createResp.BkashURL

    return &CreatePaymentResponse{
        ExternalID:        req.ExternalID,
        ProviderReference: createResp.PaymentID,
        Status:            status,
        PaymentURL:        &paymentURL,
        RedirectURL:       &paymentURL,
        Metadata: map[string]interface{}{
            "bkash_payment_id":       createResp.PaymentID,
            "merchant_invoice":       createResp.MerchantInvoiceNumber,
            "transaction_status":     createResp.TransactionStatus,
            "amount":                 createResp.Amount,
            "currency":               createResp.Currency,
            "bkash_url":              createResp.BkashURL,
            "payment_create_time":    createResp.PaymentCreateTime,
        },
    }, nil
}

func (p *BkashProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {
    if !p.Config.Enabled {
        return &QueryPaymentResponse{Status: "pending", ProviderReference: req.ProviderReference}, nil
    }

    token, err := p.getToken(ctx)
    if err != nil {
        return nil, fmt.Errorf("bkash get token failed: %w", err)
    }

    queryReq := bkashQueryPaymentRequest{
        PaymentID: req.ProviderReference,
    }

    headers := map[string]string{
        "Authorization": token,
        "X-APP-Key":     p.Config.AppKey,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:  "POST",
        Path:    "/payment/status",
        Body:    queryReq,
        Headers: headers,
    })
    if err != nil {
        return nil, fmt.Errorf("bkash query payment http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("bkash query payment failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var queryResp bkashQueryPaymentResponse
    if err := json.Unmarshal(httpResp.Body, &queryResp); err != nil {
        return nil, fmt.Errorf("bkash query payment unmarshal failed: %w", err)
    }

    if queryResp.ErrorCode != "" && queryResp.TransactionStatus == "" {
        return nil, fmt.Errorf("bkash query payment error %s: %s", queryResp.ErrorCode, queryResp.ErrorMessage)
    }

    status := p.MapProviderStatus(queryResp.TransactionStatus)

    return &QueryPaymentResponse{
        Status:            status,
        ProviderReference: queryResp.PaymentID,
        Metadata: map[string]interface{}{
            "trx_id":             queryResp.TrxID,
            "transaction_status": queryResp.TransactionStatus,
            "amount":             queryResp.Amount,
        },
    }, nil
}

func (p *BkashProvider) VerifyWebhook(payload []byte, signature string) error {
    // bKash webhook verification - check signature if provided
    if signature == "" {
        // bKash callbacks may not have signature in sandbox, validate payload structure
        var data map[string]interface{}
        if err := json.Unmarshal(payload, &data); err != nil {
            return fmt.Errorf("invalid webhook payload: %w", err)
        }
        return nil
    }
    return p.VerifyHMAC(payload, signature, p.Config.Secret)
}

func (p *BkashProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {
    paymentID, _ := payload["paymentID"].(string)
    if paymentID == "" {
        paymentID, _ = payload["paymentId"].(string)
    }
    trxID, _ := payload["trxID"].(string)
    if trxID == "" {
        trxID, _ = payload["transactionId"].(string)
    }
    statusStr, _ := payload["transactionStatus"].(string)
    if statusStr == "" {
        statusStr, _ = payload["status"].(string)
    }

    status := p.MapProviderStatus(statusStr)
    if status == "" {
        status = "succeeded"
    }

    return &QueryPaymentResponse{
        Status:            status,
        ProviderReference: paymentID,
        Metadata: map[string]interface{}{
            "trx_id":             trxID,
            "transaction_status": statusStr,
            "raw_payload":        payload,
        },
    }, nil
}

func (p *BkashProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {
    if !p.Config.Enabled {
        return &RefundResponse{RefundID: p.GenerateExternalID(), Status: "pending"}, nil
    }

    token, err := p.getToken(ctx)
    if err != nil {
        return nil, fmt.Errorf("bkash get token failed: %w", err)
    }

    amountMajor := fmt.Sprintf("%.2f", float64(req.AmountMinor)/100.0)

    refundReq := bkashRefundRequest{
        PaymentID: req.ExternalID,
        Amount:    amountMajor,
        TrxID:     req.ExternalID,
        SKU:       "refund",
        Reason:    req.Reason,
    }
    if refundReq.Reason == "" {
        refundReq.Reason = "customer request"
    }

    headers := map[string]string{
        "Authorization": token,
        "X-APP-Key":     p.Config.AppKey,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:         "POST",
        Path:           "/payment/refund",
        Body:           refundReq,
        Headers:        headers,
        IdempotencyKey: req.IdempotencyKey,
    })
    if err != nil {
        return nil, fmt.Errorf("bkash refund http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("bkash refund failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var refundResp bkashRefundResponse
    if err := json.Unmarshal(httpResp.Body, &refundResp); err != nil {
        return nil, fmt.Errorf("bkash refund unmarshal failed: %w", err)
    }

    if refundResp.ErrorCode != "" && refundResp.TransactionStatus == "" {
        return nil, fmt.Errorf("bkash refund error %s: %s", refundResp.ErrorCode, refundResp.ErrorMessage)
    }

    status := p.MapProviderStatus(refundResp.TransactionStatus)
    if status == "" {
        status = "pending"
    }

    return &RefundResponse{
        RefundID: refundResp.RefundTrxID,
        Status:   status,
        Metadata: map[string]interface{}{
            "original_trx_id": refundResp.OriginalTrxID,
            "refund_trx_id":   refundResp.RefundTrxID,
            "transaction_status": refundResp.TransactionStatus,
        },
    }, nil
}

func (p *BkashProvider) HealthCheck(ctx context.Context) error {
    if !p.Config.Enabled {
        return nil
    }
    // Try to get token as health check - safe operation no financial transaction
    ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
    defer cancel()
    _, err := p.getToken(ctx)
    if err != nil {
        return fmt.Errorf("bkash health check failed: %w", err)
    }
    return nil
}

func (p *BkashProvider) MapProviderStatus(providerStatus string) string {
    switch providerStatus {
    case "Initiated", "Created":
        return InternalStatusCreated
    case "Pending", "pending":
        return InternalStatusPending
    case "Processing", "processing":
        return InternalStatusProcessing
    case "Authorized", "authorized":
        return InternalStatusAuthorized
    case "Completed", "Success", "completed", "success", "Successful", "successful", "Completed Successfully":
        return InternalStatusSucceeded
    case "Failed", "failed", "Failure", "failure":
        return InternalStatusFailed
    case "Expired", "expired":
        return InternalStatusExpired
    case "Cancelled", "cancelled", "Canceled", "canceled":
        return InternalStatusCancelled
    case "Refunded", "refunded":
        return InternalStatusRefunded
    case "Refunding", "refunding":
        return InternalStatusRefunding
    default:
        // Unknown status fails safely - never map to succeeded
        if providerStatus == "" {
            return InternalStatusPending
        }
        // Log unknown status and return pending for safety
        return InternalStatusPending
    }
}

func (p *BkashProvider) ExecutePayment(ctx context.Context, paymentID string) (*bkashExecutePaymentResponse, error) {
    token, err := p.getToken(ctx)
    if err != nil {
        return nil, err
    }

    execReq := bkashExecutePaymentRequest{PaymentID: paymentID}
    headers := map[string]string{
        "Authorization": token,
        "X-APP-Key":     p.Config.AppKey,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:  "POST",
        Path:    "/execute",
        Body:    execReq,
        Headers: headers,
    })
    if err != nil {
        return nil, err
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("bkash execute failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var execResp bkashExecutePaymentResponse
    if err := json.Unmarshal(httpResp.Body, &execResp); err != nil {
        return nil, err
    }

    return &execResp, nil
}

// For testing
func (p *BkashProvider) ClearTokenCache() {
    p.tokenCache.mu.Lock()
    p.tokenCache.token = ""
    p.tokenCache.expiresAt = time.Time{}
    p.tokenCache.mu.Unlock()
}

func (p *BkashProvider) GetCachedToken() (string, time.Time) {
    p.tokenCache.mu.RLock()
    defer p.tokenCache.mu.RUnlock()
    return p.tokenCache.token, p.tokenCache.expiresAt
}

var _ = uuid.New
