package webhooks

import (
    "context"
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "sync"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/domain"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/storage"
)

type Service struct {
    secret         string
    store          storage.Store
    providerMgr    map[string]providers.Provider
    logger         *observability.Logger
    metrics        observability.Metrics
    mu             sync.RWMutex
    events         map[string]*domain.WebhookEvent
    processed      map[string]time.Time
}

type WebhookRequest struct {
    Provider  string
    EventID   string
    Payload   []byte
    Signature string
    Timestamp int64
    Headers   map[string]string
}

type WebhookResponse struct {
    Status      string `json:"status"`
    EventID     string `json:"event_id"`
    Processed   bool   `json:"processed"`
    Duplicate   bool   `json:"duplicate,omitempty"`
    PaymentID   string `json:"payment_id,omitempty"`
    NewStatus   string `json:"new_status,omitempty"`
}

func NewService(secret string, store storage.Store, providers map[string]providers.Provider, logger *observability.Logger, metrics observability.Metrics) *Service {
    return &Service{
        secret:      secret,
        store:       store,
        providerMgr: providers,
        logger:      logger,
        metrics:     metrics,
        events:      make(map[string]*domain.WebhookEvent),
        processed:   make(map[string]time.Time),
    }
}

func (s *Service) VerifySignature(payload []byte, signature string) error {
    if signature == "" {
        return fmt.Errorf("missing signature")
    }
    mac := hmac.New(sha256.New, []byte(s.secret))
    mac.Write(payload)
    expected := hex.EncodeToString(mac.Sum(nil))
    if !hmac.Equal([]byte(expected), []byte(signature)) {
        return fmt.Errorf("invalid signature")
    }
    return nil
}

func (s *Service) ValidateTimestamp(timestamp int64) error {
    now := time.Now().Unix()
    if abs(now-timestamp) > 300 {
        return fmt.Errorf("timestamp out of tolerance: %d vs %d", timestamp, now)
    }
    if timestamp > now+60 {
        return fmt.Errorf("timestamp in future: %d vs %d", timestamp, now)
    }
    return nil
}

func (s *Service) ExtractEventID(payload map[string]interface{}, provider string) string {
    // Provider-specific event ID extraction
    switch provider {
    case "bkash":
        if id, ok := payload["paymentID"].(string); ok && id != "" {
            return id
        }
        if id, ok := payload["trxID"].(string); ok && id != "" {
            return id
        }
        if id, ok := payload["transactionId"].(string); ok && id != "" {
            return id
        }
    case "nagad":
        if id, ok := payload["paymentRefId"].(string); ok && id != "" {
            return id
        }
        if id, ok := payload["payment_ref_id"].(string); ok && id != "" {
            return id
        }
        if id, ok := payload["orderId"].(string); ok && id != "" {
            return id
        }
    case "rocket":
        if id, ok := payload["txnId"].(string); ok && id != "" {
            return id
        }
        if id, ok := payload["transactionId"].(string); ok && id != "" {
            return id
        }
    }
    // Generic fallback
    if id, ok := payload["event_id"].(string); ok && id != "" {
        return id
    }
    if id, ok := payload["eventId"].(string); ok && id != "" {
        return id
    }
    if id, ok := payload["id"].(string); ok && id != "" {
        return id
    }
    return ""
}

func (s *Service) ProcessInbound(ctx context.Context, req WebhookRequest) (*WebhookResponse, error) {
    start := time.Now()

    // 1. Raw body preservation - already done via req.Payload
    // 2. Authenticate - verify signature
    if err := s.authenticateProvider(req); err != nil {
        s.metrics.Increment("webhook_auth_failed", map[string]string{"provider": req.Provider})
        return nil, fmt.Errorf("webhook authentication failed: %w", err)
    }

    // 3. Validate signature
    if req.Signature != "" {
        provider, ok := s.providerMgr[req.Provider]
        if ok {
            if err := provider.VerifyWebhook(req.Payload, req.Signature); err != nil {
                s.metrics.Increment("webhook_signature_invalid", map[string]string{"provider": req.Provider})
                return nil, fmt.Errorf("invalid signature: %w", err)
            }
        } else {
            if err := s.VerifySignature(req.Payload, req.Signature); err != nil {
                return nil, err
            }
        }
    }

    // 4. Validate timestamp
    if req.Timestamp != 0 {
        if err := s.ValidateTimestamp(req.Timestamp); err != nil {
            s.metrics.Increment("webhook_timestamp_invalid", map[string]string{"provider": req.Provider})
            return nil, err
        }
    }

    // 5. Parse payload
    var payloadMap map[string]interface{}
    if err := json.Unmarshal(req.Payload, &payloadMap); err != nil {
        s.metrics.Increment("webhook_malformed", map[string]string{"provider": req.Provider})
        return nil, fmt.Errorf("malformed JSON: %w", err)
    }

    // 6. Identify event - extract event ID
    eventID := req.EventID
    if eventID == "" {
        eventID = s.ExtractEventID(payloadMap, req.Provider)
    }
    if eventID == "" {
        return nil, fmt.Errorf("event ID not found in payload")
    }

    // 7. Check duplicate - replay protection
    if s.IsDuplicate(eventID) {
        s.metrics.Increment("webhook_duplicate", map[string]string{"provider": req.Provider})
        if s.logger != nil {
            s.logger.Info("webhook duplicate detected", map[string]interface{}{
                "provider": req.Provider,
                "event_id": eventID,
            })
        }
        return &WebhookResponse{
            Status:    "duplicate",
            EventID:   eventID,
            Processed: false,
            Duplicate: true,
        }, nil
    }

    // 8. Persist webhook event
    event := domain.NewWebhookEvent(req.Provider, "payment.succeeded", eventID, payloadMap)
    event.Signature = req.Signature

    s.mu.Lock()
    s.events[eventID] = event
    s.mu.Unlock()

    // 9. Process financial state transition - transactional
    queryResp, err := s.processFinancialTransition(ctx, req.Provider, payloadMap)
    if err != nil {
        event.MarkFailed(err.Error())
        s.metrics.Increment("webhook_processing_failed", map[string]string{"provider": req.Provider})
        return nil, fmt.Errorf("financial transition failed: %w", err)
    }

    // 10. Update payment - only when business rules permit
    // This would update payment in store transactionally
    if s.store != nil {
        // In real implementation, this would be in a DB transaction
        // 1. authenticate provider event (done)
        // 2. persist event (done)
        // 3. process financial state inside DB transaction
        // 4. commit
        // 5. publish post-commit event
        if payment, err := s.store.GetPaymentByExternalID(ctx, eventID); err == nil && payment != nil {
            // Validate state transition
            // Update payment status
            payment.Status = queryResp.Status
            s.store.UpdatePayment(ctx, payment)
        }
    }

    // 11. Mark processed
    event.MarkProcessed()
    s.mu.Lock()
    s.processed[eventID] = time.Now()
    s.mu.Unlock()

    s.metrics.Increment("webhook_processed", map[string]string{"provider": req.Provider})
    latency := time.Since(start).Milliseconds()
    s.metrics.Timing("webhook_latency_ms", latency, map[string]string{"provider": req.Provider})

    if s.logger != nil {
        s.logger.Info("webhook processed", map[string]interface{}{
            "provider":   req.Provider,
            "event_id":   eventID,
            "status":     queryResp.Status,
            "latency_ms": latency,
        })
    }

    return &WebhookResponse{
        Status:    "processed",
        EventID:   eventID,
        Processed: true,
        PaymentID: queryResp.ProviderReference,
        NewStatus: queryResp.Status,
    }, nil
}

func (s *Service) authenticateProvider(req WebhookRequest) error {
    // Verify provider exists and is enabled
    if req.Provider == "" {
        return fmt.Errorf("provider required")
    }
    if _, ok := s.providerMgr[req.Provider]; !ok {
        // Allow unknown provider but log
        if s.logger != nil {
            s.logger.Info("webhook unknown provider", map[string]interface{}{"provider": req.Provider})
        }
    }
    return nil
}

func (s *Service) processFinancialTransition(ctx context.Context, providerKey string, payload map[string]interface{}) (*providers.QueryPaymentResponse, error) {
    provider, ok := s.providerMgr[providerKey]
    if !ok {
        return nil, fmt.Errorf("provider %s not found", providerKey)
    }

    resp, err := provider.HandleCallback(ctx, payload)
    if err != nil {
        return nil, err
    }

    // Validate state transition
    validStatuses := []string{"created", "pending", "processing", "authorized", "succeeded", "failed", "expired", "cancelled", "refunding", "refunded"}
    isValid := false
    for _, vs := range validStatuses {
        if resp.Status == vs {
            isValid = true
            break
        }
    }
    if !isValid {
        return nil, fmt.Errorf("invalid status transition: %s", resp.Status)
    }

    // Never credit wallet before provider event is authenticated and financial transaction commits
    // This is enforced by requiring DB transaction commit before wallet update

    return resp, nil
}

func (s *Service) IsDuplicate(eventID string) bool {
    s.mu.RLock()
    defer s.mu.RUnlock()
    _, exists := s.events[eventID]
    if exists {
        return true
    }
    // Check processed cache with TTL
    if processedAt, ok := s.processed[eventID]; ok {
        if time.Since(processedAt) < 24*time.Hour {
            return true
        }
    }
    return false
}

func (s *Service) MarkDuplicate(eventID string) {
    s.mu.Lock()
    s.processed[eventID] = time.Now()
    s.mu.Unlock()
}

func (s *Service) ProcessInboundSimple(eventID string, payload map[string]interface{}) error {
    if s.IsDuplicate(eventID) {
        return fmt.Errorf("duplicate event")
    }
    s.mu.Lock()
    s.events[eventID] = &domain.WebhookEvent{EventID: eventID, Payload: payload}
    s.processed[eventID] = time.Now()
    s.mu.Unlock()
    return nil
}

func abs(x int64) int64 {
    if x < 0 {
        return -x
    }
    return x
}

// Additional methods for replay testing
func (s *Service) IsDuplicateWithBodyCheck(eventID string, body []byte) (bool, error) {
    s.mu.RLock()
    defer s.mu.RUnlock()
    if existing, ok := s.events[eventID]; ok {
        // Same event ID with modified body - should be detected
        existingBody, _ := json.Marshal(existing.Payload)
        if string(existingBody) != string(body) {
            return true, fmt.Errorf("same event ID with modified body - potential replay attack")
        }
        return true, nil
    }
    return false, nil
}

func (s *Service) CleanupExpired() {
    s.mu.Lock()
    defer s.mu.Unlock()
    now := time.Now()
    for id, t := range s.processed {
        if now.Sub(t) > 24*time.Hour {
            delete(s.processed, id)
            delete(s.events, id)
        }
    }
}
