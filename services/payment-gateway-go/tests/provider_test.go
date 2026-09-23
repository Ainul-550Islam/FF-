package tests

import (
	"context"
	"testing"

	"github.com/ffarena/payment-gateway-go/internal/observability"
	"github.com/ffarena/payment-gateway-go/internal/providers"
)

func testObservability() (*observability.Logger, observability.Metrics) {
	logger := observability.NewLogger("payment-gateway-test", "test", "1.0.0")
	metrics := observability.NewInMemoryMetrics()
	return logger, metrics
}

func TestManualProvider(t *testing.T) {
	logger, metrics := testObservability()
	p := providers.NewManualProvider(providers.ProviderConfig{}, logger, metrics)
	if p.Key() != "manual" {
		t.Error("expected manual key")
	}
	if !p.SupportsCurrency("BDT") {
		t.Error("manual should support BDT")
	}
	if !p.SupportsRefund() {
		t.Error("manual should support refund")
	}
	req := providers.CreatePaymentRequest{UserID: 1, AmountMinor: 1000, Currency: "BDT", Provider: "manual", ExternalID: "ext-123", IdempotencyKey: "idem-123"}
	resp, err := p.CreatePayment(context.Background(), req)
	if err != nil {
		t.Fatalf("create payment failed: %v", err)
	}
	// Manual payments always start pending — they require admin review
	// before they may be marked succeeded.
	if resp.Status != "pending" {
		t.Errorf("expected pending, got %s", resp.Status)
	}
	if resp.Metadata["requires_review"] != true {
		t.Error("manual payment must flag requires_review")
	}
}

func TestBkashProvider(t *testing.T) {
	logger, metrics := testObservability()
	p := providers.NewBkashProvider(providers.ProviderConfig{MerchantID: "test"}, logger, metrics)
	if p.Key() != "bkash" {
		t.Error("expected bkash key")
	}
	if !p.SupportsCurrency("BDT") {
		t.Error("bkash should support BDT")
	}
	if p.SupportsCurrency("USD") {
		t.Error("bkash should not support USD")
	}
	req := providers.CreatePaymentRequest{UserID: 1, AmountMinor: 500, Currency: "BDT", Provider: "bkash", ExternalID: "ext-123", IdempotencyKey: "idem-123"}
	_, err := p.CreatePayment(context.Background(), req)
	if err == nil {
		t.Error("expected error for amount < 1000")
	}
}

func TestNagadProvider(t *testing.T) {
	logger, metrics := testObservability()
	p := providers.NewNagadProvider(providers.ProviderConfig{MerchantID: "test"}, logger, metrics)
	if p.Key() != "nagad" {
		t.Error("expected nagad key")
	}
}

func TestRocketProvider(t *testing.T) {
	logger, metrics := testObservability()
	p := providers.NewRocketProvider(providers.ProviderConfig{MerchantID: "test"}, logger, metrics)
	if p.Key() != "rocket" {
		t.Error("expected rocket key")
	}
	if p.SupportsRefund() {
		t.Error("rocket should not support refund")
	}
}
