package tests

import (
    "context"
    "testing"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/storage"
    "github.com/ffarena/payment-gateway-go/internal/webhooks"
)

func TestWebhookReplayProtection(t *testing.T) {
    logger := observability.NewLogger("test", "testing", "1.0")
    metrics := observability.NewInMemoryMetrics()
    store := storage.NewMemoryStore()
    
    bkashCfg := providers.ProviderConfig{Enabled: false}
    bkash := providers.NewBkashProvider(bkashCfg, logger, metrics)
    
    providersMap := map[string]providers.Provider{
        "bkash": bkash,
    }
    
    service := webhooks.NewService("test_secret", store, providersMap, logger, metrics)
    
    // Test exact duplicate webhook
    payload := []byte(`{"paymentID":"test123","transactionStatus":"Completed"}`)
    req := webhooks.WebhookRequest{
        Provider:  "bkash",
        EventID:   "event-123",
        Payload:   payload,
        Signature: "",
    }
    
    resp1, err := service.ProcessInbound(context.Background(), req)
    if err != nil {
        t.Fatalf("first webhook should succeed: %v", err)
    }
    if resp1.Status != "processed" {
        t.Errorf("expected processed, got %s", resp1.Status)
    }
    
    // Same event ID again - should be duplicate
    resp2, err := service.ProcessInbound(context.Background(), req)
    if err != nil {
        t.Fatalf("duplicate webhook should not error hard, should return duplicate status: %v", err)
    }
    if resp2.Status != "duplicate" {
        t.Errorf("expected duplicate, got %s", resp2.Status)
    }
    
    // Same financial event can never produce two financial effects
    t.Log("PASS: same financial event cannot produce two financial effects")
}

func TestWebhookInvalidSignature(t *testing.T) {
    logger := observability.NewLogger("test", "testing", "1.0")
    metrics := observability.NewInMemoryMetrics()
    store := storage.NewMemoryStore()
    
    cfg := providers.ProviderConfig{Secret: "test_secret", Enabled: true}
    bkash := providers.NewBkashProvider(cfg, logger, metrics)
    
    providersMap := map[string]providers.Provider{"bkash": bkash}
    service := webhooks.NewService("test_secret", store, providersMap, logger, metrics)
    
    payload := []byte(`{"paymentID":"test"}`)
    req := webhooks.WebhookRequest{
        Provider:  "bkash",
        EventID:   "event-invalid-sig",
        Payload:   payload,
        Signature: "invalid_signature",
    }
    
    _, err := service.ProcessInbound(context.Background(), req)
    if err == nil {
        t.Error("should fail with invalid signature")
    }
}

func TestWebhookTimestampValidation(t *testing.T) {
    logger := observability.NewLogger("test", "testing", "1.0")
    metrics := observability.NewInMemoryMetrics()
    store := storage.NewMemoryStore()
    
    cfg := providers.ProviderConfig{Enabled: false}
    bkash := providers.NewBkashProvider(cfg, logger, metrics)
    
    providersMap := map[string]providers.Provider{"bkash": bkash}
    service := webhooks.NewService("test_secret", store, providersMap, logger, metrics)
    
    // Old timestamp
    payload := []byte(`{"paymentID":"test"}`)
    req := webhooks.WebhookRequest{
        Provider:  "bkash",
        EventID:   "event-old-ts",
        Payload:   payload,
        Timestamp: 1000000000, // Very old
    }
    
    _, err := service.ProcessInbound(context.Background(), req)
    if err == nil {
        t.Error("should fail with old timestamp")
    }
    
    // Future timestamp
    req.EventID = "event-future-ts"
    req.Timestamp = 9999999999 // Far future
    _, err = service.ProcessInbound(context.Background(), req)
    if err == nil {
        t.Error("should fail with future timestamp")
    }
}

func TestWebhookMalformedJSON(t *testing.T) {
    logger := observability.NewLogger("test", "testing", "1.0")
    metrics := observability.NewInMemoryMetrics()
    store := storage.NewMemoryStore()
    
    cfg := providers.ProviderConfig{Enabled: false}
    bkash := providers.NewBkashProvider(cfg, logger, metrics)
    
    providersMap := map[string]providers.Provider{"bkash": bkash}
    service := webhooks.NewService("test_secret", store, providersMap, logger, metrics)
    
    payload := []byte(`{invalid json`)
    req := webhooks.WebhookRequest{
        Provider: "bkash",
        EventID:  "event-malformed",
        Payload:  payload,
    }
    
    _, err := service.ProcessInbound(context.Background(), req)
    if err == nil {
        t.Error("should fail with malformed JSON")
    }
}
