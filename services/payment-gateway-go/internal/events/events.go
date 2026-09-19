package events
import "time"
const (
    EventPaymentCreated="payment.created.v1"
    EventPaymentSucceeded="payment.succeeded.v1"
    EventPaymentFailed="payment.failed.v1"
    EventPaymentRefunded="payment.refunded.v1"
    EventPayoutCreated="payout.created.v1"
    EventPayoutCompleted="payout.completed.v1"
    EventWebhookReceived="webhook.received.v1"
)
type Event struct{ID string `json:"id"`; Type string `json:"type"`; Version string `json:"version"`; Source string `json:"source"`; Timestamp time.Time `json:"timestamp"`; Data map[string]interface{} `json:"data"`; Metadata map[string]interface{} `json:"metadata,omitempty"`}
func NewEvent(eventType string, data map[string]interface{}) *Event {
    return &Event{Type:eventType,Version:"v1",Source:"payment-gateway-go",Timestamp:time.Now().UTC(),Data:data,Metadata:map[string]interface{}{"service":"payment-gateway-go"}}
}
type Publisher interface{Publish(event *Event) error}
type InMemoryPublisher struct{events []*Event}
func NewInMemoryPublisher() *InMemoryPublisher {return &InMemoryPublisher{events: make([]*Event,0)}}
func (p *InMemoryPublisher) Publish(event *Event) error{p.events=append(p.events,event); return nil}
func (p *InMemoryPublisher) Events() []*Event{return p.events}
