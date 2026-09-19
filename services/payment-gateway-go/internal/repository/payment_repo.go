package repository
import ("context"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/storage")
type PaymentRepository struct{store storage.Store}
func NewPaymentRepository(store storage.Store) *PaymentRepository {return &PaymentRepository{store:store}}
func (r *PaymentRepository) Create(ctx context.Context, payment *models.Payment) error {return r.store.CreatePayment(ctx,payment)}
func (r *PaymentRepository) GetByID(ctx context.Context, id string) (*models.Payment, error) {return r.store.GetPaymentByID(ctx,id)}
func (r *PaymentRepository) GetByExternalID(ctx context.Context, externalID string) (*models.Payment, error) {return r.store.GetPaymentByExternalID(ctx,externalID)}
func (r *PaymentRepository) Update(ctx context.Context, payment *models.Payment) error {return r.store.UpdatePayment(ctx,payment)}
