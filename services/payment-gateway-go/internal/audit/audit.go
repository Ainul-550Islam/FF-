package audit
import ("sync"; "time")
type Action string
const (ActionPaymentCreated Action="payment.created"; ActionPaymentSucceeded Action="payment.succeeded"; ActionPaymentFailed Action="payment.failed"; ActionPaymentRefunded Action="payment.refunded"; ActionWalletCredited Action="wallet.credited"; ActionWalletDebited Action="wallet.debited"; ActionPayoutCreated Action="payout.created"; ActionWebhookReceived Action="webhook.received"; ActionIdempotencyHit Action="idempotency.hit")
type LogEntry struct{ID string `json:"id"`; Action Action `json:"action"`; UserID *int64 `json:"user_id,omitempty"`; Actor string `json:"actor"`; ResourceType string `json:"resource_type"`; ResourceID string `json:"resource_id"`; Details map[string]interface{} `json:"details,omitempty"`; RequestID string `json:"request_id"`; Timestamp time.Time `json:"timestamp"`}
type Logger struct{mu sync.RWMutex; entries []*LogEntry}
func NewLogger() *Logger {return &Logger{entries: make([]*LogEntry,0)}}
func (l *Logger) Log(entry *LogEntry){l.mu.Lock(); defer l.mu.Unlock(); entry.Timestamp=time.Now().UTC(); l.entries=append(l.entries,entry)}
func (l *Logger) Entries() []*LogEntry{l.mu.RLock(); defer l.mu.RUnlock(); return l.entries}
func (l *Logger) EntriesByUser(userID int64) []*LogEntry{l.mu.RLock(); defer l.mu.RUnlock(); var result []*LogEntry; for _,e:=range l.entries{if e.UserID!=nil&&*e.UserID==userID{result=append(result,e)}}; return result}
