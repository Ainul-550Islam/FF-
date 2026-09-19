package workers
import ("context"; "log"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/queue")
type WebhookWorker struct{queue *queue.Queue; metrics observability.Metrics; logger *observability.Logger}
func NewWebhookWorker(q *queue.Queue, metrics observability.Metrics, logger *observability.Logger) *WebhookWorker {return &WebhookWorker{queue:q,metrics:metrics,logger:logger}}
func (w *WebhookWorker) Start(ctx context.Context){
    ticker:=time.NewTicker(2*time.Second); defer ticker.Stop()
    for{select{case <-ctx.Done(): return; case <-ticker.C: w.process(ctx)}}
}
func (w *WebhookWorker) process(ctx context.Context){
    job,err:=w.queue.Dequeue()
    if err!=nil||job==nil{return}
    if job.Type!=queue.JobWebhookProcess{return}
    log.Printf("Processing webhook job %s",job.ID)
    w.metrics.Increment("worker.webhook.processed",nil)
    if err:=w.queue.Complete(job.ID); err!=nil{log.Printf("Failed to complete webhook job %s: %v",job.ID,err)}
}
