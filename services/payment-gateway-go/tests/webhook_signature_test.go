// Proven 2026-10-07 (audit packet R9): `go vet ./...` clean and
// `go test ./...` green in-sandbox on go1.27.1 AND CI-exact go1.22.12.
// The `go` CI job still flips to required only after a green CI run
// (see docs/TEST_EVIDENCE.md graduation log).
package tests

import (
    "context"
    "strings"
    "testing"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/webhooks"
)

func TestProcessInboundRejectsUnsignedKnownProvider(t *testing.T) {
    manual := providers.NewManualProvider(providers.ProviderConfig{Secret: "test-manual-secret", Enabled: true}, nil, nil)
    svc := webhooks.NewService("test-global-secret", nil, map[string]providers.Provider{"manual": manual}, nil, observability.NewInMemoryMetrics())
    _, err := svc.ProcessInbound(context.Background(), webhooks.WebhookRequest{
        Provider: "manual",
        Payload:  []byte(`{"event_id":"evt-test-1"}`),
    })
    if err == nil || !strings.Contains(err.Error(), "missing signature") {
        t.Fatalf("unsigned known-provider webhook should be rejected, got %v", err)
    }
}

func TestProcessInboundRejectsUnsignedWhenGlobalSecretSet(t *testing.T) {
    svc := webhooks.NewService("test-global-secret", nil, map[string]providers.Provider{}, nil, observability.NewInMemoryMetrics())
    _, err := svc.ProcessInbound(context.Background(), webhooks.WebhookRequest{
        Provider: "unknown-provider",
        Payload:  []byte(`{"event_id":"evt-test-2"}`),
    })
    if err == nil || !strings.Contains(err.Error(), "missing signature") {
        t.Fatalf("unsigned webhook with a configured global secret should be rejected, got %v", err)
    }
}

func TestProcessInboundDevOpenWithoutSecretsFailsSafe(t *testing.T) {
    // Unknown provider + empty global secret: falls through to the financial
    // transition, which fails safely ("provider not found") — no money moves.
    svc := webhooks.NewService("", nil, map[string]providers.Provider{}, nil, observability.NewInMemoryMetrics())
    _, err := svc.ProcessInbound(context.Background(), webhooks.WebhookRequest{
        Provider: "unknown-provider",
        Payload:  []byte(`{"event_id":"evt-test-3"}`),
    })
    if err == nil || !strings.Contains(err.Error(), "provider unknown-provider not found") {
        t.Fatalf("expected safe transition failure, got %v", err)
    }
}
