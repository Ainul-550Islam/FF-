package services
import ("context"; "fmt"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type PayoutService struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewPayoutService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *PayoutService {return &PayoutService{store:store,metrics:metrics,logger:logger}}
func (s *PayoutService) CreatePayout(ctx context.Context, userID int64, amountMinor int64, currency, externalID, idempotencyKey string) (*models.Payout, error){
    if amountMinor<=0{return nil, fmt.Errorf("amount must be positive")}
    if existing,err:=s.store.GetPayoutByIdempotencyKey(ctx,idempotencyKey); err==nil&&existing!=nil{return existing, nil}
    payout:=&models.Payout{ID:uuid.New().String(),UserID:userID,AmountMinor:amountMinor,Currency:currency,Status:"pending",ExternalID:externalID,Provider:"manual",IdempotencyKey:idempotencyKey}
    if payout.ExternalID==""{payout.ExternalID=uuid.New().String()}
    if payout.IdempotencyKey==""{payout.IdempotencyKey=uuid.New().String()}
    if err:=s.store.CreatePayout(ctx,payout); err!=nil{return nil, err}
    s.metrics.Increment("payout.created",nil)
    return payout, nil
}
