package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PayoutRepository struct{store storage.Store}
func NewPayoutRepository(store storage.Store) *PayoutRepository {return &PayoutRepository{store:store}}
func (r *PayoutRepository) Create(ctx context.Context, payout *models.Payout) error {return r.store.CreatePayout(ctx,payout)}
func (r *PayoutRepository) GetByID(ctx context.Context, id string) (*models.Payout, error) {return r.store.GetPayoutByID(ctx,id)}
func (r *PayoutRepository) Update(ctx context.Context, payout *models.Payout) error {return r.store.UpdatePayout(ctx,payout)}
