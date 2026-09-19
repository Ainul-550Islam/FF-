package providers

import (
    "context"
    "crypto"
    "crypto/rand"
    "crypto/rsa"
    "crypto/sha256"
    "crypto/x509"
    "encoding/base64"
    "encoding/json"
    "encoding/pem"
    "errors"
    "fmt"
    "strings"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers/client"
)

type NagadProvider struct {
    BaseProvider
    httpClient *client.ProviderHTTPClient
    logger     *observability.Logger
    metrics    observability.Metrics
    privateKey *rsa.PrivateKey
    publicKey  *rsa.PublicKey
}

type nagadSensitiveData struct {
    MerchantID string `json:"merchantId"`
    OrderID    string `json:"orderId"`
    Amount     string `json:"amount"`
    Challenge  string `json:"challenge"`
    Currency   string `json:"currency,omitempty"`
}

type nagadInitRequest struct {
    MerchantID      string `json:"merchantId"`
    OrderID         string `json:"orderId"`
    Amount          string `json:"amount"`
    Challenge       string `json:"challenge"`
    Currency        string `json:"currency"`
    CallbackURL     string `json:"callbackURL"`
    AdditionalInfo  map[string]interface{} `json:"additionalMerchantInfo,omitempty"`
}

type nagadInitResponse struct {
    SensitiveData string `json:"sensitiveData"`
    Signature     string `json:"signature"`
    MerchantID    string `json:"merchantId"`
    OrderID       string `json:"orderId"`
    Challenge     string `json:"challenge"`
}

type nagadCheckoutResponse struct {
    CallBackURL string `json:"callBackUrl"`
    PaymentRefID string `json:"paymentRefId"`
    Status      string `json:"status"`
    StatusCode  string `json:"statusCode"`
    Message     string `json:"message,omitempty"`
}

type nagadVerifyRequest struct {
    PaymentRefID string `json:"paymentRefId"`
    OrderID      string `json:"orderId"`
}

type nagadVerifyResponse struct {
    MerchantID         string `json:"merchantId"`
    OrderID            string `json:"orderId"`
    PaymentRefID       string `json:"paymentRefId"`
    Amount             string `json:"amount"`
    ClientMobileNo     string `json:"clientMobileNo"`
    MerchantMobileNo   string `json:"merchantMobileNo"`
    OrderDateTime      string `json:"orderDateTime"`
    IssuerPaymentDateTime string `json:"issuerPaymentDateTime"`
    Status             string `json:"status"`
    StatusCode         string `json:"statusCode"`
    CancelIssuerDateTime string `json:"cancelIssuerDateTime,omitempty"`
    CancelIssuerRefNo  string `json:"cancelIssuerRefNo,omitempty"`
}

func NewNagadProvider(cfg ProviderConfig, logger *observability.Logger, metrics observability.Metrics) *NagadProvider {
    timeout := time.Duration(cfg.TimeoutMs) * time.Millisecond
    if timeout == 0 {
        timeout = 20 * time.Second
    }
    httpClient := client.NewProviderHTTPClient(client.HTTPClientConfig{
        BaseURL:  cfg.BaseURL,
        Timeout:  timeout,
        Provider: "nagad",
        Logger:   logger,
        Metrics:  metrics,
    })

    provider := &NagadProvider{
        BaseProvider: BaseProvider{Config: cfg},
        httpClient:   httpClient,
        logger:       logger,
        metrics:      metrics,
    }

    // Parse keys if provided - never log them
    if cfg.PrivateKey != "" {
        if privKey, err := provider.parsePrivateKey(cfg.PrivateKey); err == nil {
            provider.privateKey = privKey
        } else {
            if logger != nil {
                logger.Error("nagad private key parse failed", map[string]interface{}{"error": err.Error()})
            }
        }
    }
    if cfg.PublicKey != "" {
        if pubKey, err := provider.parsePublicKey(cfg.PublicKey); err == nil {
            provider.publicKey = pubKey
        } else {
            if logger != nil {
                logger.Error("nagad public key parse failed", map[string]interface{}{"error": err.Error()})
            }
        }
    }

    return provider
}

func (p *NagadProvider) Key() string { return "nagad" }
func (p *NagadProvider) Label() string { return "Nagad" }
func (p *NagadProvider) SupportsCurrency(currency string) bool { return currency == "BDT" }
func (p *NagadProvider) SupportsRefund() bool { return true }
func (p *NagadProvider) Capabilities() []string { return []string{"create", "query", "refund", "webhook", "verify", "checkout"} }
func (p *NagadProvider) Metadata() map[string]interface{} {
    return map[string]interface{}{
        "type": "mobile_banking", "currency": "BDT",
        "sandbox_url": "http://sandbox.mynagad.com:10060",
        "live_url": "https://api.mynagad.com",
        "auth_flow": "rsa_signature",
        "crypto": "RSA-SHA256",
        "supported_operations": []string{"initialize", "checkout", "verify", "refund"},
    }
}
func (p *NagadProvider) IsEnabled() bool { return p.Config.Enabled }
func (p *NagadProvider) ValidateConfig() error {
    if !p.Config.Enabled {
        return nil
    }
    if p.Config.MerchantID == "" {
        return errors.New("nagad merchant_id required")
    }
    if p.Config.PrivateKey == "" {
        return errors.New("nagad private_key required")
    }
    if p.Config.PublicKey == "" {
        return errors.New("nagad public_key required")
    }
    if p.Config.BaseURL == "" {
        return errors.New("nagad base_url required")
    }
    return nil
}

func (p *NagadProvider) parsePrivateKey(keyStr string) (*rsa.PrivateKey, error) {
    // Handle both PEM and raw base64
    keyStr = strings.TrimSpace(keyStr)
    if strings.Contains(keyStr, "BEGIN") {
        block, _ := pem.Decode([]byte(keyStr))
        if block == nil {
            return nil, errors.New("failed to decode PEM private key")
        }
        // Try PKCS8
        if priv, err := x509.ParsePKCS8PrivateKey(block.Bytes); err == nil {
            if rsaPriv, ok := priv.(*rsa.PrivateKey); ok {
                return rsaPriv, nil
            }
        }
        // Try PKCS1
        if priv, err := x509.ParsePKCS1PrivateKey(block.Bytes); err == nil {
            return priv, nil
        }
        return nil, errors.New("unsupported private key format")
    } else {
        // Assume base64 encoded PKCS8
        decoded, err := base64.StdEncoding.DecodeString(keyStr)
        if err != nil {
            decoded, err = base64.RawStdEncoding.DecodeString(keyStr)
            if err != nil {
                return nil, fmt.Errorf("base64 decode private key failed: %w", err)
            }
        }
        if priv, err := x509.ParsePKCS8PrivateKey(decoded); err == nil {
            if rsaPriv, ok := priv.(*rsa.PrivateKey); ok {
                return rsaPriv, nil
            }
        }
        if priv, err := x509.ParsePKCS1PrivateKey(decoded); err == nil {
            return priv, nil
        }
        return nil, errors.New("failed to parse private key from base64")
    }
}

func (p *NagadProvider) parsePublicKey(keyStr string) (*rsa.PublicKey, error) {
    keyStr = strings.TrimSpace(keyStr)
    if strings.Contains(keyStr, "BEGIN") {
        block, _ := pem.Decode([]byte(keyStr))
        if block == nil {
            return nil, errors.New("failed to decode PEM public key")
        }
        pub, err := x509.ParsePKIXPublicKey(block.Bytes)
        if err != nil {
            return nil, err
        }
        if rsaPub, ok := pub.(*rsa.PublicKey); ok {
            return rsaPub, nil
        }
        return nil, errors.New("not RSA public key")
    } else {
        decoded, err := base64.StdEncoding.DecodeString(keyStr)
        if err != nil {
            decoded, err = base64.RawStdEncoding.DecodeString(keyStr)
            if err != nil {
                return nil, fmt.Errorf("base64 decode public key failed: %w", err)
            }
        }
        pub, err := x509.ParsePKIXPublicKey(decoded)
        if err != nil {
            return nil, err
        }
        if rsaPub, ok := pub.(*rsa.PublicKey); ok {
            return rsaPub, nil
        }
        return nil, errors.New("not RSA public key")
    }
}

func (p *NagadProvider) encryptWithPublicKey(plaintext []byte) (string, error) {
    if p.publicKey == nil {
        return "", errors.New("public key not configured")
    }
    encrypted, err := rsa.EncryptOAEP(sha256.New(), rand.Reader, p.publicKey, plaintext, nil)
    if err != nil {
        return "", fmt.Errorf("RSA encrypt failed: %w", err)
    }
    return base64.StdEncoding.EncodeToString(encrypted), nil
}

func (p *NagadProvider) decryptWithPrivateKey(ciphertextB64 string) ([]byte, error) {
    if p.privateKey == nil {
        return nil, errors.New("private key not configured")
    }
    ciphertext, err := base64.StdEncoding.DecodeString(ciphertextB64)
    if err != nil {
        return nil, fmt.Errorf("base64 decode failed: %w", err)
    }
    plaintext, err := rsa.DecryptOAEP(sha256.New(), rand.Reader, p.privateKey, ciphertext, nil)
    if err != nil {
        return nil, fmt.Errorf("RSA decrypt failed: %w", err)
    }
    return plaintext, nil
}

func (p *NagadProvider) signWithPrivateKey(data []byte) (string, error) {
    if p.privateKey == nil {
        return "", errors.New("private key not configured")
    }
    hashed := sha256.Sum256(data)
    signature, err := rsa.SignPKCS1v15(rand.Reader, p.privateKey, crypto.SHA256, hashed[:])
    if err != nil {
        return "", fmt.Errorf("RSA sign failed: %w", err)
    }
    return base64.StdEncoding.EncodeToString(signature), nil
}

func (p *NagadProvider) verifyWithPublicKey(data []byte, signatureB64 string) error {
    if p.publicKey == nil {
        return errors.New("public key not configured")
    }
    signature, err := base64.StdEncoding.DecodeString(signatureB64)
    if err != nil {
        return fmt.Errorf("base64 decode signature failed: %w", err)
    }
    hashed := sha256.Sum256(data)
    return rsa.VerifyPKCS1v15(p.publicKey, crypto.SHA256, hashed[:], signature)
}

func (p *NagadProvider) generateChallenge() string {
    // Generate random challenge for Nagad
    b := make([]byte, 16)
    rand.Read(b)
    return fmt.Sprintf("%x", b)
}

func (p *NagadProvider) CreatePayment(ctx context.Context, req CreatePaymentRequest) (*CreatePaymentResponse, error) {
    if !p.Config.Enabled {
        resp := p.CreateBasePayment(req)
        resp.Metadata = map[string]interface{}{"paymentRefId": resp.ProviderReference, "amount": req.AmountMinor, "mock": true}
        resp.Status = "pending"
        return &resp, nil
    }

    if p.privateKey == nil || p.publicKey == nil {
        return nil, errors.New("nagad keys not configured - cannot create real payment")
    }

    amountMajor := fmt.Sprintf("%.2f", float64(req.AmountMinor)/100.0)
    challenge := p.generateChallenge()
    callbackURL := req.CallbackURL
    if callbackURL == "" {
        callbackURL = p.Config.CallbackURL
        if callbackURL == "" {
            callbackURL = "https://example.com/nagad/callback"
        }
    }

    // Step 1: Initialize payment - create sensitive data
    sensitive := nagadSensitiveData{
        MerchantID: p.Config.MerchantID,
        OrderID:    req.ExternalID,
        Amount:     amountMajor,
        Challenge:  challenge,
        Currency:   req.Currency,
    }

    sensitiveJSON, err := json.Marshal(sensitive)
    if err != nil {
        return nil, fmt.Errorf("marshal sensitive data failed: %w", err)
    }

    // Encrypt sensitive data with PG public key
    encryptedSensitive, err := p.encryptWithPublicKey(sensitiveJSON)
    if err != nil {
        return nil, fmt.Errorf("encrypt sensitive data failed: %w", err)
    }

    // Sign sensitive data with merchant private key
    signature, err := p.signWithPrivateKey(sensitiveJSON)
    if err != nil {
        return nil, fmt.Errorf("sign sensitive data failed: %w", err)
    }

    initReq := map[string]interface{}{
        "merchantId":    p.Config.MerchantID,
        "orderId":       req.ExternalID,
        "amount":        amountMajor,
        "challenge":     challenge,
        "currency":      req.Currency,
        "sensitiveData": encryptedSensitive,
        "signature":     signature,
    }

    // Call Nagad initialize endpoint
    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:         "POST",
        Path:           "/api/dfs/check-out/initialize/" + p.Config.MerchantID + "/" + req.ExternalID,
        Body:           initReq,
        IdempotencyKey: req.IdempotencyKey,
        RequestID:      req.IdempotencyKey,
    })
    if err != nil {
        return nil, fmt.Errorf("nagad initialize http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("nagad initialize failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var initResp nagadInitResponse
    if err := json.Unmarshal(httpResp.Body, &initResp); err != nil {
        return nil, fmt.Errorf("nagad initialize unmarshal failed: %w", err)
    }

    if initResp.SensitiveData == "" {
        return nil, fmt.Errorf("nagad initialize empty sensitiveData")
    }

    // Decrypt response sensitive data
    decrypted, err := p.decryptWithPrivateKey(initResp.SensitiveData)
    if err != nil {
        // In sandbox, response might be plain JSON if decryption fails, try to use as is
        if p.logger != nil {
            p.logger.Error("nagad decrypt response failed, trying plain", map[string]interface{}{"error": err.Error()})
        }
        decrypted = []byte(initResp.SensitiveData)
    }

    var checkoutResp nagadCheckoutResponse
    if err := json.Unmarshal(decrypted, &checkoutResp); err != nil {
        // Try to unmarshal original body if decrypted is not JSON
        if err2 := json.Unmarshal(httpResp.Body, &checkoutResp); err2 != nil {
            return nil, fmt.Errorf("nagad checkout response unmarshal failed: %w (original: %s)", err, string(httpResp.Body))
        }
    }

    if checkoutResp.CallBackURL == "" && checkoutResp.PaymentRefID == "" {
        // Fallback: if sandbox returns different format, try to extract payment URL
        var rawMap map[string]interface{}
        json.Unmarshal(decrypted, &rawMap)
        if url, ok := rawMap["callBackUrl"].(string); ok {
            checkoutResp.CallBackURL = url
        }
        if ref, ok := rawMap["paymentRefId"].(string); ok {
            checkoutResp.PaymentRefID = ref
        }
    }

    status := p.MapProviderStatus(checkoutResp.Status)
    paymentURL := checkoutResp.CallBackURL
    if paymentURL == "" {
        // Construct sandbox checkout URL if not provided
        paymentURL = p.Config.BaseURL + "/check-out/" + initResp.SensitiveData
    }

    return &CreatePaymentResponse{
        ExternalID:        req.ExternalID,
        ProviderReference: checkoutResp.PaymentRefID,
        Status:            status,
        PaymentURL:        &paymentURL,
        RedirectURL:       &paymentURL,
        Metadata: map[string]interface{}{
            "payment_ref_id": checkoutResp.PaymentRefID,
            "callback_url":   checkoutResp.CallBackURL,
            "status":         checkoutResp.Status,
            "status_code":    checkoutResp.StatusCode,
            "challenge":      challenge,
            "amount":         amountMajor,
        },
    }, nil
}

func (p *NagadProvider) QueryPayment(ctx context.Context, req QueryPaymentRequest) (*QueryPaymentResponse, error) {
    if !p.Config.Enabled {
        return &QueryPaymentResponse{Status: "pending", ProviderReference: req.ProviderReference}, nil
    }

    if p.privateKey == nil {
        return nil, errors.New("nagad private key not configured")
    }

    // Verify payment via Nagad verify endpoint
    verifyReq := nagadVerifyRequest{
        PaymentRefID: req.ProviderReference,
        OrderID:      req.ExternalID,
    }

    verifyJSON, err := json.Marshal(verifyReq)
    if err != nil {
        return nil, err
    }

    encrypted, err := p.encryptWithPublicKey(verifyJSON)
    if err != nil {
        return nil, err
    }

    signature, err := p.signWithPrivateKey(verifyJSON)
    if err != nil {
        return nil, err
    }

    body := map[string]interface{}{
        "merchantId":    p.Config.MerchantID,
        "orderId":       req.ExternalID,
        "paymentRefId":  req.ProviderReference,
        "sensitiveData": encrypted,
        "signature":     signature,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method: "POST",
        Path:   "/api/dfs/verify/payment/" + req.ProviderReference,
        Body:   body,
    })
    if err != nil {
        return nil, fmt.Errorf("nagad verify http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("nagad verify failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var verifyResp nagadVerifyResponse
    // Try to decrypt if response is encrypted
    var rawResp map[string]interface{}
    if err := json.Unmarshal(httpResp.Body, &rawResp); err == nil {
        if sensitive, ok := rawResp["sensitiveData"].(string); ok && sensitive != "" {
            decrypted, err := p.decryptWithPrivateKey(sensitive)
            if err == nil {
                json.Unmarshal(decrypted, &verifyResp)
            } else {
                json.Unmarshal(httpResp.Body, &verifyResp)
            }
        } else {
            json.Unmarshal(httpResp.Body, &verifyResp)
        }
    } else {
        return nil, fmt.Errorf("nagad verify unmarshal failed: %w", err)
    }

    status := p.MapProviderStatus(verifyResp.Status)

    return &QueryPaymentResponse{
        Status:            status,
        ProviderReference: verifyResp.PaymentRefID,
        Metadata: map[string]interface{}{
            "status":      verifyResp.Status,
            "status_code": verifyResp.StatusCode,
            "amount":      verifyResp.Amount,
        },
    }, nil
}

func (p *NagadProvider) VerifyWebhook(payload []byte, signature string) error {
    // Nagad webhook verification - verify signature with public key
    if signature == "" {
        // In sandbox, signature might be in payload
        var data map[string]interface{}
        if err := json.Unmarshal(payload, &data); err != nil {
            return fmt.Errorf("invalid webhook payload: %w", err)
        }
        // Check if payload has signature field
        if sig, ok := data["signature"].(string); ok && sig != "" {
            // Verify signature of sensitive data
            if sensitive, ok := data["sensitiveData"].(string); ok {
                return p.verifyWithPublicKey([]byte(sensitive), sig)
            }
        }
        return nil
    }

    // Verify provided signature
    return p.verifyWithPublicKey(payload, signature)
}

func (p *NagadProvider) HandleCallback(ctx context.Context, payload map[string]interface{}) (*QueryPaymentResponse, error) {
    // Nagad callback handling - extract paymentRefId and status
    paymentRefID, _ := payload["paymentRefId"].(string)
    if paymentRefID == "" {
        paymentRefID, _ = payload["payment_ref_id"].(string)
    }
    if paymentRefID == "" {
        paymentRefID, _ = payload["paymentRefID"].(string)
    }

    statusStr, _ := payload["status"].(string)
    if statusStr == "" {
        statusStr, _ = payload["statusCode"].(string)
    }

    // If payload contains sensitiveData, decrypt it
    if sensitive, ok := payload["sensitiveData"].(string); ok && sensitive != "" && p.privateKey != nil {
        decrypted, err := p.decryptWithPrivateKey(sensitive)
        if err == nil {
            var decryptedMap map[string]interface{}
            if err := json.Unmarshal(decrypted, &decryptedMap); err == nil {
                if ref, ok := decryptedMap["paymentRefId"].(string); ok {
                    paymentRefID = ref
                }
                if s, ok := decryptedMap["status"].(string); ok {
                    statusStr = s
                }
                // Merge decrypted data into payload
                for k, v := range decryptedMap {
                    payload[k] = v
                }
            }
        }
    }

    status := p.MapProviderStatus(statusStr)
    if status == "" {
        status = "succeeded"
    }

    return &QueryPaymentResponse{
        Status:            status,
        ProviderReference: paymentRefID,
        Metadata: map[string]interface{}{
            "raw_payload": payload,
            "status":      statusStr,
        },
    }, nil
}

func (p *NagadProvider) Refund(ctx context.Context, req RefundRequest) (*RefundResponse, error) {
    if !p.Config.Enabled {
        return &RefundResponse{RefundID: p.GenerateExternalID(), Status: "pending"}, nil
    }

    // Nagad refund - check if officially supported
    // According to docs, refund requires separate API
    if p.privateKey == nil {
        return nil, errors.New("nagad private key required for refund")
    }

    amountMajor := fmt.Sprintf("%.2f", float64(req.AmountMinor)/100.0)

    refundData := map[string]interface{}{
        "merchantId":   p.Config.MerchantID,
        "orderId":      req.ExternalID,
        "paymentRefId": req.ExternalID,
        "amount":       amountMajor,
        "reason":       req.Reason,
    }

    refundJSON, err := json.Marshal(refundData)
    if err != nil {
        return nil, err
    }

    encrypted, err := p.encryptWithPublicKey(refundJSON)
    if err != nil {
        return nil, err
    }

    signature, err := p.signWithPrivateKey(refundJSON)
    if err != nil {
        return nil, err
    }

    body := map[string]interface{}{
        "merchantId":    p.Config.MerchantID,
        "paymentRefId":  req.ExternalID,
        "sensitiveData": encrypted,
        "signature":     signature,
    }

    httpResp, err := p.httpClient.Do(ctx, client.RequestOptions{
        Method:         "POST",
        Path:           "/api/dfs/refund/" + req.ExternalID,
        Body:           body,
        IdempotencyKey: req.IdempotencyKey,
    })
    if err != nil {
        return nil, fmt.Errorf("nagad refund http failed: %w", err)
    }

    if httpResp.StatusCode != 200 {
        return nil, fmt.Errorf("nagad refund failed status %d: %s", httpResp.StatusCode, string(httpResp.Body))
    }

    var respMap map[string]interface{}
    json.Unmarshal(httpResp.Body, &respMap)

    refundID := p.GenerateExternalID()
    if id, ok := respMap["refundRefId"].(string); ok {
        refundID = id
    }

    return &RefundResponse{
        RefundID: refundID,
        Status:   "pending",
        Metadata: respMap,
    }, nil
}

func (p *NagadProvider) HealthCheck(ctx context.Context) error {
    if !p.Config.Enabled {
        return nil
    }
    // Safe health check - verify keys are parseable, no financial transaction
    if p.privateKey == nil {
        return errors.New("nagad private key not configured")
    }
    if p.publicKey == nil {
        return errors.New("nagad public key not configured")
    }
    // Try to sign and verify a test message
    testData := []byte("health-check")
    sig, err := p.signWithPrivateKey(testData)
    if err != nil {
        return fmt.Errorf("nagad health check sign failed: %w", err)
    }
    // Verify with public key if possible (if key pair matches, this would work, but PG public key is different)
    // So just check sign succeeded
    _ = sig
    return nil
}

func (p *NagadProvider) MapProviderStatus(providerStatus string) string {
    switch providerStatus {
    case "Success", "Successful", "success", "successful", "Completed", "completed", "00", "000", "00_0000_000":
        return InternalStatusSucceeded
    case "Pending", "pending", "Initiated", "initiated":
        return InternalStatusPending
    case "Processing", "processing":
        return InternalStatusProcessing
    case "Failed", "failed", "Failure", "failure", "Aborted", "aborted":
        return InternalStatusFailed
    case "Cancelled", "cancelled", "Canceled", "canceled":
        return InternalStatusCancelled
    case "Refunded", "refunded":
        return InternalStatusRefunded
    case "Refunding", "refunding":
        return InternalStatusRefunding
    default:
        if providerStatus == "" {
            return InternalStatusPending
        }
        // Unknown status fails safely - never map to succeeded
        return InternalStatusPending
    }
}
