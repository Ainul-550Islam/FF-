package services
import ("context"; "fmt"; "github.com/ffarena/payment-gateway-go/internal/manager"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/providers"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type PaymentService struct{manager *manager.Manager; store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPaymentService(mgr *manager.Manager, store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PaymentService {return &PaymentService{manager:mgr,store:store,metrics:metrics,logger:logger}}
func (s *PaymentService) CreatePayment(ctx context.Context, req providers.CreatePaymentRequest) (*models.Payment, error){
    if existing,err:=s.store.GetPaymentByIdempotencyKey(ctx,req.IdempotencyKey); err==nil&&existing!=nil{return existing, nil}
    provider,ok:=s.manager.Get(req.Provider)
    if !ok{return nil, fmt.Errorf("unsupported provider: %s",req.Provider)}
    if !provider.SupportsCurrency(req.Currency){return nil, fmt.Errorf("currency %s not supported by %s",req.Currency,req.Provider)}
    resp,err:=provider.CreatePayment(ctx,req)
    if err!=nil{s.metrics.Increment("payment.create.failed",map[string]string{"provider":req.Provider}); return nil, err}
    payment:=&models.Payment{ID:uuid.New().String(),UserID:req.UserID,Provider:req.Provider,ExternalID:req.ExternalID,AmountMinor:req.AmountMinor,Currency:req.Currency,Status:resp.Status,IdempotencyKey:req.IdempotencyKey}
    if err:=s.store.CreatePayment(ctx,payment); err!=nil{return nil, fmt.Errorf("store payment: %w",err)}
    s.metrics.Increment("payment.created",map[string]string{"provider":req.Provider})
    s.logger.Info("payment created",map[string]interface{}{"payment_id":payment.ID,"provider":req.Provider,"user_id":req.UserID})
    return payment, nil
}
func (s *PaymentService) QueryPayment(ctx context.Context, externalID string) (*models.Payment, error){return s.store.GetPaymentByExternalID(ctx,externalID)}
