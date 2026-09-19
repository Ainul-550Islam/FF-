package providers

import (
    "crypto/hmac"
    "crypto/sha256"
    "encoding/hex"
    "encoding/json"
    "fmt"
    "github.com/google/uuid"
)

type ProviderConfig struct {
    BaseURL     string `json:"base_url"`
    Secret      string `json:"-"`
    MerchantID  string `json:"merchant_id"`
    StoreID     string `json:"store_id"`
    TimeoutMs   int    `json:"timeout_ms"`
    Sandbox     bool   `json:"sandbox"`
    // bKash specific
    AppKey      string `json:"-"`
    AppSecret   string `json:"-"`
    Username    string `json:"-"`
    Password    string `json:"-"`
    // Nagad specific
    MerchantNumber string `json:"merchant_number"`
    PrivateKey     string `json:"-"`
    PublicKey      string `json:"-"`
    CallbackURL    string `json:"callback_url"`
    // General
    Enabled     bool   `json:"enabled"`
    APIKey      string `json:"-"`
}

type BaseProvider struct {
    Config ProviderConfig
}

func (b *BaseProvider) GenerateExternalID() string {
    return uuid.New().String()
}

func (b *BaseProvider) CreateBasePayment(req CreatePaymentRequest) CreatePaymentResponse {
    return CreatePaymentResponse{
        ExternalID:        req.ExternalID,
        ProviderReference: b.GenerateExternalID(),
        Status:            "pending",
        Metadata:          map[string]interface{}{"provider": req.Provider, "currency": req.Currency},
    }
}

func (b *BaseProvider) VerifyHMAC(payload []byte, signature, secret string) error {
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write(payload)
    expected := hex.EncodeToString(mac.Sum(nil))
    if !hmac.Equal([]byte(expected), []byte(signature)) {
        return fmt.Errorf("invalid HMAC signature")
    }
    return nil
}

func GenerateHMAC(secret, message string) string {
    mac := hmac.New(sha256.New, []byte(secret))
    mac.Write([]byte(message))
    return hex.EncodeToString(mac.Sum(nil))
}

func (b *BaseProvider) MarshalPayload(v interface{}) ([]byte, error) {
    return json.Marshal(v)
}

func (b *BaseProvider) ValidateBaseConfig() error {
    if !b.Config.Enabled {
        return nil
    }
    if b.Config.BaseURL == "" {
        return fmt.Errorf("base_url required")
    }
    return nil
}

func (b *BaseProvider) IsEnabled() bool {
    return b.Config.Enabled
}

func (b *BaseProvider) GetTimeoutMs() int {
    if b.Config.TimeoutMs <= 0 {
        return 15000
    }
    return b.Config.TimeoutMs
}
