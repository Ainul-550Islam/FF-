package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/providers"; "github.com/ffarena/payment-gateway-go/internal/testing")
func TestPaymentCreation(t *testing.T){
    payment:=testing.CreateTestPayment(1,"manual","ext-123",1000,"BDT","idem-123")
    if payment.UserID!=1{t.Errorf("expected userID 1, got %d",payment.UserID)}
    if payment.Provider!="manual"{t.Errorf("expected manual provider")}
}
func TestPaymentValidation(t *testing.T){
    payment:=&models.Payment{AmountMinor:-100,Provider:"",ExternalID:""}
    if err:=payment.Validate(); err==nil{t.Error("expected validation error for negative amount")}
}
func TestProviderFactory(t *testing.T){
    factory:=providers.NewFactory(map[string]providers.ProviderConfig{})
    for _,key:=range factory.SupportedProviders(){
        p,err:=factory.Create(key)
        if err!=nil{t.Errorf("failed to create provider %s: %v",key,err)}
        if p.Key()!=key{t.Errorf("provider key mismatch: expected %s, got %s",key,p.Key())}
    }
}
