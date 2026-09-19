package main
import ("context"; "log"; "os"; "os/signal"; "syscall"; "github.com/ffarena/payment-gateway-go/internal/config"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/queue"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/ffarena/payment-gateway-go/internal/workers")
func main(){
    cfg,err:=config.Load()
    if err!=nil{log.Fatalf("Failed to load config: %v",err)}
    logger:=observability.NewLogger(cfg.ServiceID,cfg.Env,cfg.Version)
    metrics:=observability.NewInMemoryMetrics()
    store:=storage.NewMemoryStore()
    q:=queue.NewQueue()
    paymentWorker:=workers.NewPaymentWorker(q,store,metrics,logger)
    webhookWorker:=workers.NewWebhookWorker(q,metrics,logger)
    ctx, cancel:=context.WithCancel(context.Background())
    defer cancel()
    go paymentWorker.Start(ctx)
    go webhookWorker.Start(ctx)
    logger.Info("workers started",nil)
    quit:=make(chan os.Signal,1)
    signal.Notify(quit,syscall.SIGINT,syscall.SIGTERM)
    <-quit
    cancel()
    logger.Info("workers stopped",nil)
}
