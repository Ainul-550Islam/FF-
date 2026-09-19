package workers
import ("context"; "log"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/queue"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PaymentWorker struct{queue *queue.Queue; store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPaymentWorker(q *queue.Queue, store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PaymentWorker {return &PaymentWorker{queue:q,store:store,metrics:metrics,logger:logger}}
func (w *PaymentWorker) Start(ctx context.Context){
    ticker:=time.NewTicker(5*time.Second); defer ticker.Stop()
    for{select{case <-ctx.Done(): return; case <-ticker.C: w.process(ctx)}}
}
func (w *PaymentWorker) process(ctx context.Context){
    job,err:=w.queue.Dequeue()
    if err!=nil||job==nil{return}
    if job.Type!=queue.JobPaymentVerify{return}
    log.Printf("Processing payment verify job %s",job.ID)
    w.metrics.Increment("worker.payment_verify.processed",nil)
    if err:=w.queue.Complete(job.ID); err!=nil{log.Printf("Failed to complete job %s: %v",job.ID,err)}
}
