package security
import ("crypto/hmac"; "crypto/sha256"; "encoding/hex")
func VerifyHMAC(payload []byte, signature, secret string) bool {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write(payload)
    expected:=hex.EncodeToString(mac.Sum(nil))
    return hmac.Equal([]byte(expected),[]byte(signature))
}
func GenerateHMAC(secret, message string) string {
    mac:=hmac.New(sha256.New,[]byte(secret)); mac.Write([]byte(message)); return hex.EncodeToString(mac.Sum(nil))
}
