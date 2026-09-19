package middleware
import ("bytes"; "crypto/hmac"; "crypto/sha256"; "encoding/hex"; "io"; "net/http"; "strings")
func HMACVerify(secret string) func(http.Handler) http.Handler {
    return func(next http.Handler) http.Handler {
        return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request){
            if !isWebhookPath(r.URL.Path){next.ServeHTTP(w,r); return}
            signature:=r.Header.Get("X-Signature")
            if signature==""{signature=r.Header.Get("X-Webhook-Signature")}
            if signature==""{w.WriteHeader(401); w.Write([]byte(`{"error":"missing_signature"}`)); return}
            body,err:=io.ReadAll(r.Body)
            if err!=nil{w.WriteHeader(400); return}
            r.Body=io.NopCloser(bytes.NewBuffer(body))
            mac:=hmac.New(sha256.New,[]byte(secret))
            mac.Write(body)
            expected:=hex.EncodeToString(mac.Sum(nil))
            if !hmac.Equal([]byte(expected),[]byte(signature)){w.WriteHeader(401); w.Write([]byte(`{"error":"invalid_signature"}`)); return}
            next.ServeHTTP(w,r)
        })
    }
}
func isWebhookPath(path string) bool {return strings.Contains(path,"webhook")}
