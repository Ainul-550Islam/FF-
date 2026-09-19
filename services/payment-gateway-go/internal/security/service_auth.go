package security
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex"; "fmt"; "net/http"; "strconv"; "time"; "github.com/google/uuid")
func SignRequest(secret, method, path, body string, timestamp int64, nonce string) string {
    message:=fmt.Sprintf("%s:%s:%s:%d:%s",method,path,body,timestamp,nonce)
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write([]byte(message)); return hex.EncodeToString(mac.Sum(nil))
}
func VerifyRequest(secret, method, path, body, signature string, timestamp int64, nonce string, toleranceSeconds int64) error {
    now:=time.Now().Unix()
    if abs(now-timestamp)>toleranceSeconds{return fmt.Errorf("timestamp out of tolerance")}
    expected:=SignRequest(secret,method,path,body,timestamp,nonce)
    if !hmac.Equal([]byte(expected),[]byte(signature)){return fmt.Errorf("invalid signature")}
    return nil
}
func GenerateTimestamp() int64 {return time.Now().Unix()}
func ValidateTimestamp(timestamp int64, toleranceSeconds int64) error {
    now:=time.Now().Unix()
    if abs(now-timestamp)>toleranceSeconds{return fmt.Errorf("timestamp out of tolerance")}
    return nil
}
func GenerateNonce() string {return uuid.New().String()}
func GenerateHeaders(secret, method, path, body string) map[string]string {
    timestamp:=GenerateTimestamp(); nonce:=GenerateNonce(); signature:=SignRequest(secret,method,path,body,timestamp,nonce)
    return map[string]string{"X-Timestamp":strconv.FormatInt(timestamp,10),"X-Nonce":nonce,"X-Signature":signature,"X-Service-ID":"payment-gateway-go"}
}
func abs(x int64) int64 {if x<0{return -x}; return x}
func SignRequestHTTP(r *http.Request, secret, body string){
    timestamp:=GenerateTimestamp(); nonce:=GenerateNonce(); signature:=SignRequest(secret,r.Method,r.URL.Path,body,timestamp,nonce)
    r.Header.Set("X-Timestamp",strconv.FormatInt(timestamp,10)); r.Header.Set("X-Nonce",nonce); r.Header.Set("X-Signature",signature)
}
