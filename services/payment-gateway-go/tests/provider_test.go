package tests
import ("context"; "testing"; "github.com/ffarena/payment-gateway-go/internal/providers")
func TestManualProvider(t *testing.T){
    p:=providers.NewManualProvider(providers.ProviderConfig{})
    if p.Key()!="manual"{t.Error("expected manual key")}
    if !p.SupportsCurrency("BDT"){t.Error("manual should support BDT")}
    if !p.SupportsRefund(){t.Error("manual should support refund")}
    req:=providers.CreatePaymentRequest{UserID:1,AmountMinor:1000,Currency:"BDT",Provider:"manual",ExternalID:"ext-123",IdempotencyKey:"idem-123"}
    resp,err:=p.CreatePayment(context.Background(),req)
    if err!=nil{t.Fatalf("create payment failed: %v",err)}
    if resp.Status!="succeeded"{t.Errorf("expected succeeded, got %s",resp.Status)}
}
func TestBkashProvider(t *testing.T){
    p:=providers.NewBkashProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="bkash"{t.Error("expected bkash key")}
    if !p.SupportsCurrency("BDT"){t.Error("bkash should support BDT")}
    if p.SupportsCurrency("USD"){t.Error("bkash should not support USD")}
    req:=providers.CreatePaymentRequest{UserID:1,AmountMinor:500,Currency:"BDT",Provider:"bkash",ExternalID:"ext-123",IdempotencyKey:"idem-123"}
    _,err:=p.CreatePayment(context.Background(),req)
    if err==nil{t.Error("expected error for amount < 1000")}
}
func TestNagadProvider(t *testing.T){
    p:=providers.NewNagadProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="nagad"{t.Error("expected nagad key")}
}
func TestRocketProvider(t *testing.T){
    p:=providers.NewRocketProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="rocket"{t.Error("expected rocket key")}
    if p.SupportsRefund(){t.Error("rocket should not support refund")}
}
