package settlement
import ("context"; "fmt"; "time"; "github.com/ffarena/payment-gateway-go/internal/observability"; "github.com/ffarena/payment-gateway-go/internal/storage"; "github.com/google/uuid")
type Service struct{store storage.Store; metrics observability.Metrics; logger *observability.Logger}
func NewService(store storage.Store, metrics observability.Metrics, logger *observability.Logger) *Service {return &Service{store:store,metrics:metrics,logger:logger}}
type Settlement struct{ID string `json:"id"`; TournamentID int64 `json:"tournament_id"`; TotalAmountMinor int64 `json:"total_amount_minor"`; Currency string `json:"currency"`; Status string `json:"status"`; IdempotencyKey string `json:"idempotency_key"`; CompletedAt *time.Time `json:"completed_at,omitempty"`; CreatedAt time.Time `json:"created_at"`}
func (s *Service) CreateSettlement(ctx context.Context, tournamentID int64, totalAmountMinor int64, currency, idempotencyKey string) (*Settlement, error){
    settlement:=&Settlement{ID:uuid.New().String(),TournamentID:tournamentID,TotalAmountMinor:totalAmountMinor,Currency:currency,Status:"pending",IdempotencyKey:idempotencyKey,CreatedAt:time.Now()}
    if settlement.IdempotencyKey==""{settlement.IdempotencyKey=uuid.New().String()}
    s.metrics.Increment("settlement.created",nil)
    s.logger.Info("settlement created",map[string]interface{}{"settlement_id":settlement.ID,"tournament_id":tournamentID})
    return settlement, nil
}
func (s *Service) Complete(ctx context.Context, settlementID string) error{s.metrics.Increment("settlement.completed",nil); return nil}
func (s *Service) DistributePrizes(ctx context.Context, settlementID string, distributions map[int64]int64) error{
    for userID,amount:=range distributions{if amount<=0{return fmt.Errorf("invalid amount for user %d",userID)}}
    s.metrics.Increment("settlement.prize_distribution",nil)
    return nil
}
