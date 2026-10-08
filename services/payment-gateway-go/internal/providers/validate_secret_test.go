// Proven 2026-10-07 (audit packet R9): `go vet ./...` clean and
// `go test ./...` green in-sandbox on go1.27.1 AND CI-exact go1.22.12.
// The `go` CI job still flips to required only after a green CI run
// (see docs/TEST_EVIDENCE.md graduation log).
package providers

import "testing"

// NOTE: providers are built with nested literals (not constructors) so the
// tests stay hermetic — ValidateConfig reads only Config.
func bkashWith(cfg ProviderConfig) *BkashProvider {
    return &BkashProvider{BaseProvider: BaseProvider{Config: cfg}}
}

func rocketWith(cfg ProviderConfig) *RocketProvider {
    return &RocketProvider{BaseProvider: BaseProvider{Config: cfg}}
}

func manualWith(cfg ProviderConfig) *ManualProvider {
    return &ManualProvider{BaseProvider: BaseProvider{Config: cfg}}
}

func TestHMACProvidersRequireSecretWhenEnabled(t *testing.T) {
    bkash := bkashWith(ProviderConfig{Enabled: true, BaseURL: "https://x", AppKey: "k", AppSecret: "s", Username: "u", Password: "p"})
    if err := bkash.ValidateConfig(); err == nil {
        t.Fatal("enabled bkash without Secret should fail")
    }
    rocket := rocketWith(ProviderConfig{Enabled: true, MerchantID: "m"})
    if err := rocket.ValidateConfig(); err == nil {
        t.Fatal("enabled rocket without Secret should fail")
    }
    manual := manualWith(ProviderConfig{Enabled: false})
    if err := manual.ValidateConfig(); err == nil {
        t.Fatal("manual without Secret should fail even when flagged disabled (always-on fallback)")
    }
}

func TestHMACProvidersPassWithSecret(t *testing.T) {
    bkash := bkashWith(ProviderConfig{Enabled: true, BaseURL: "https://x", AppKey: "k", AppSecret: "s", Username: "u", Password: "p", Secret: "some-webhook-secret"})
    if err := bkash.ValidateConfig(); err != nil {
        t.Fatalf("bkash with Secret should pass, got %v", err)
    }
    rocket := rocketWith(ProviderConfig{Enabled: true, MerchantID: "m", Secret: "some-webhook-secret"})
    if err := rocket.ValidateConfig(); err != nil {
        t.Fatalf("rocket with Secret should pass, got %v", err)
    }
    manual := manualWith(ProviderConfig{Secret: "some-webhook-secret"})
    if err := manual.ValidateConfig(); err != nil {
        t.Fatalf("manual with Secret should pass, got %v", err)
    }
}

func TestDisabledProvidersSkipSecretCheck(t *testing.T) {
    bkash := bkashWith(ProviderConfig{Enabled: false})
    if err := bkash.ValidateConfig(); err != nil {
        t.Fatalf("disabled bkash should skip validation, got %v", err)
    }
    rocket := rocketWith(ProviderConfig{Enabled: false})
    if err := rocket.ValidateConfig(); err != nil {
        t.Fatalf("disabled rocket should skip validation, got %v", err)
    }
}
