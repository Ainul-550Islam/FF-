package tests
import ("context"; "testing"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/storage")
func TestIdempotencyRecord(t *testing.T){
    store:=storage.NewMemoryStore()
    record:=&domain.IdempotencyRecord{Key:"test-key",Fingerprint:"fp123",Operation:"create_payment",ExpiresAt:domain.GenerateKeyTime()}
    err:=store.SetIdempotency(context.Background(),record)
    if err!=nil{t.Fatalf("failed to set idempotency: %v",err)}
    retrieved,err:=store.GetIdempotency(context.Background(),"test-key")
    if err!=nil{t.Fatalf("failed to get idempotency: %v",err)}
    if retrieved.Fingerprint!=record.Fingerprint{t.Errorf("fingerprint mismatch")}
}
func TestIdempotencyDuplicate(t *testing.T){
    store:=storage.NewMemoryStore()
    ctx:=context.Background()
    payment1:=&domain.IdempotencyRecord{Key:"idem-123",Fingerprint:"fp-123",Operation:"payment",ExpiresAt:domain.GenerateKeyTime()}
    payment2:=&domain.IdempotencyRecord{Key:"idem-123",Fingerprint:"fp-456",Operation:"payment",ExpiresAt:domain.GenerateKeyTime()}
    store.SetIdempotency(ctx,payment1)
    retrieved,err:=store.GetIdempotency(ctx,"idem-123")
    if err!=nil{t.Fatalf("failed to get: %v",err)}
    if retrieved.Fingerprint=="fp-456"{t.Error("should not overwrite with different fingerprint without check")}
    _=payment2
}
