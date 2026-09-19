package providers

import (
    "fmt"
    "github.com/ffarena/payment-gateway-go/internal/observability"
)

type Factory struct {
    configs map[string]ProviderConfig
    logger  *observability.Logger
    metrics observability.Metrics
}

func NewFactory(configs map[string]ProviderConfig, logger *observability.Logger, metrics observability.Metrics) *Factory {
    return &Factory{configs: configs, logger: logger, metrics: metrics}
}

func (f *Factory) Create(key string) (Provider, error) {
    cfg, ok := f.configs[key]
    if !ok {
        cfg = ProviderConfig{Enabled: true}
    }
    // Default enabled true for manual, false for others unless explicitly enabled
    if key != "manual" && !ok {
        cfg.Enabled = false
    }

    switch key {
    case "manual":
        return NewManualProvider(cfg, f.logger, f.metrics), nil
    case "bkash":
        return NewBkashProvider(cfg, f.logger, f.metrics), nil
    case "nagad":
        return NewNagadProvider(cfg, f.logger, f.metrics), nil
    case "rocket":
        return NewRocketProvider(cfg, f.logger, f.metrics), nil
    default:
        return nil, &ProviderNotFoundError{Key: key}
    }
}

func (f *Factory) SupportedProviders() []string { return []string{"manual", "bkash", "nagad", "rocket"} }
func (f *Factory) IsSupported(key string) bool {
    for _, k := range f.SupportedProviders() {
        if k == key {
            return true
        }
    }
    return false
}
func (f *Factory) CreateAll() map[string]Provider {
    result := make(map[string]Provider)
    for _, key := range f.SupportedProviders() {
        if p, err := f.Create(key); err == nil {
            result[key] = p
        }
    }
    return result
}

func (f *Factory) CreateEnabled() map[string]Provider {
    result := make(map[string]Provider)
    for _, key := range f.SupportedProviders() {
        if p, err := f.Create(key); err == nil {
            if p.IsEnabled() {
                result[key] = p
            }
        }
    }
    return result
}

type ProviderNotFoundError struct{ Key string }
func (e *ProviderNotFoundError) Error() string { return "provider not found: " + e.Key }

func (f *Factory) GetConfig(key string) (ProviderConfig, bool) {
    cfg, ok := f.configs[key]
    return cfg, ok
}

func (f *Factory) ValidateAll() error {
    for _, key := range f.SupportedProviders() {
        if p, err := f.Create(key); err == nil {
            if err := p.ValidateConfig(); err != nil {
                return fmt.Errorf("provider %s config invalid: %w", key, err)
            }
        }
    }
    return nil
}
