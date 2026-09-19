package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/security")
func TestHMACVerify(t *testing.T){
    secret:="test_secret"
    payload:=[]byte(`{"event":"payment.succeeded"}`)
    signature:=security.GenerateHMAC(secret,string(payload))
    if !security.VerifyHMAC(payload,signature,secret){t.Error("HMAC verification failed")}
    if security.VerifyHMAC(payload,"invalid",secret){t.Error("should fail with invalid signature")}
}
