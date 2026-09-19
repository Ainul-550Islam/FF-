package domain
import "time"
const (WebhookStateReceived="received"; WebhookStateValidated="validated"; WebhookStateProcessing="processing"; WebhookStateProcessed="processed"; WebhookStateFailed="failed"; WebhookStateDuplicate="duplicate")
type WebhookEvent struct {
    ID string `json:"id"`
    Provider string `json:"provider"`
    EventType string `json:"event_type"`
    EventID string `json:"event_id"`
    Payload map[string]interface{} `json:"payload"`
    Signature string `json:"signature,omitempty"`
    State string `json:"state"`
    Attempts int `json:"attempts"`
    LastError *string `json:"last_error,omitempty"`
    ProcessedAt *time.Time `json:"processed_at,omitempty"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func NewWebhookEvent(provider, eventType, eventID string, payload map[string]interface{}) *WebhookEvent {
    now:=time.Now().UTC()
    return &WebhookEvent{Provider:provider,EventType:eventType,EventID:eventID,Payload:payload,State:WebhookStateReceived,Attempts:0,CreatedAt:now,UpdatedAt:now}
}
func (w *WebhookEvent) CanRetry() bool {return w.Attempts<3&&w.State!=WebhookStateProcessed}
func (w *WebhookEvent) MarkProcessed(){now:=time.Now().UTC(); w.State=WebhookStateProcessed; w.ProcessedAt=&now; w.UpdatedAt=now}
func (w *WebhookEvent) MarkFailed(errMsg string){w.State=WebhookStateFailed; w.LastError=&errMsg; w.Attempts++; w.UpdatedAt=time.Now().UTC()}
