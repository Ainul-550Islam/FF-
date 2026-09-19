package handlers

import (
    "encoding/json"
    "io"
    "net/http"
    "strconv"

    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/webhooks"
)

type WebhookHandlerV2 struct {
    service *webhooks.Service
    metrics observability.Metrics
}

func NewWebhookHandlerV2(service *webhooks.Service, metrics observability.Metrics) *WebhookHandlerV2 {
    return &WebhookHandlerV2{service: service, metrics: metrics}
}

func (h *WebhookHandlerV2) InboundV2(w http.ResponseWriter, r *http.Request) {
    provider := r.PathValue("provider")
    if provider == "" {
        provider = r.URL.Query().Get("provider")
    }
    if provider == "" {
        provider = "unknown"
    }

    // Raw body preservation
    body, err := io.ReadAll(r.Body)
    if err != nil {
        w.Header().Set("Content-Type", "application/json")
        w.WriteHeader(400)
        json.NewEncoder(w).Encode(map[string]string{"error": "failed to read body"})
        return
    }

    // Signature extraction
    signature := r.Header.Get("X-Signature")
    if signature == "" {
        signature = r.Header.Get("X-Webhook-Signature")
    }
    if signature == "" {
        signature = r.Header.Get("X-Bkash-Signature")
    }
    if signature == "" {
        signature = r.Header.Get("X-Nagad-Signature")
    }

    // Timestamp extraction
    var timestamp int64
    if tsStr := r.Header.Get("X-Timestamp"); tsStr != "" {
        if ts, err := strconv.ParseInt(tsStr, 10, 64); err == nil {
            timestamp = ts
        }
    }
    if timestamp == 0 {
        if tsStr := r.Header.Get("X-Webhook-Timestamp"); tsStr != "" {
            if ts, err := strconv.ParseInt(tsStr, 10, 64); err == nil {
                timestamp = ts
            }
        }
    }

    // Event ID extraction from headers or query
    eventID := r.Header.Get("X-Event-ID")
    if eventID == "" {
        eventID = r.Header.Get("X-Event-Id")
    }

    req := webhooks.WebhookRequest{
        Provider:  provider,
        EventID:   eventID,
        Payload:   body,
        Signature: signature,
        Timestamp: timestamp,
        Headers:   map[string]string{},
    }

    // Copy relevant headers
    for k, v := range r.Header {
        if len(v) > 0 {
            req.Headers[k] = v[0]
        }
    }

    resp, err := h.service.ProcessInbound(r.Context(), req)
    if err != nil {
        w.Header().Set("Content-Type", "application/json")
        // Determine appropriate status code
        statusCode := 400
        errStr := err.Error()
        if contains(errStr, "signature") || contains(errStr, "authentication") {
            statusCode = 401
        } else if contains(errStr, "duplicate") {
            statusCode = 200
            json.NewEncoder(w).Encode(map[string]interface{}{
                "status":   "duplicate",
                "event_id": eventID,
            })
            return
        } else if contains(errStr, "timestamp") {
            statusCode = 400
        }
        w.WriteHeader(statusCode)
        json.NewEncoder(w).Encode(map[string]string{"error": err.Error()})
        return
    }

    w.Header().Set("Content-Type", "application/json")
    w.WriteHeader(200)
    json.NewEncoder(w).Encode(resp)
}

func contains(s, substr string) bool {
    return len(s) >= len(substr) && (s == substr || len(s) > len(substr) && search(s, substr))
}

func search(s, substr string) bool {
    for i := 0; i <= len(s)-len(substr); i++ {
        if s[i:i+len(substr)] == substr {
            return true
        }
    }
    return false
}
