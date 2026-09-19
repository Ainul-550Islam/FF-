package domain
import ("crypto/sha256"; "encoding/json"; "fmt"; "time"; "github.com/google/uuid")
type IdempotencyRecord struct {
    Key string `json:"key"`
    Fingerprint string `json:"fingerprint"`
    Operation string `json:"operation"`
    UserID *int64 `json:"user_id,omitempty"`
    RequestBody map[string]interface{} `json:"request_body,omitempty"`
    ResponseBody map[string]interface{} `json:"response_body,omitempty"`
    StatusCode *int `json:"status_code,omitempty"`
    ExpiresAt time.Time `json:"expires_at"`
    CreatedAt time.Time `json:"created_at"`
    UpdatedAt time.Time `json:"updated_at"`
}
func GenerateFingerprint(data interface{}) (string, error) {
    b,err:=json.Marshal(data)
    if err!=nil{return "", err}
    h:=sha256.Sum256(b)
    return fmt.Sprintf("%x",h), nil
}
func GenerateKey() string {return uuid.New().String()}
func NewIdempotencyRecord(key, operation string, requestBody map[string]interface{}) (*IdempotencyRecord, error) {
    fp,err:=GenerateFingerprint(requestBody)
    if err!=nil{return nil, err}
    now:=time.Now().UTC()
    return &IdempotencyRecord{Key:key,Fingerprint:fp,Operation:operation,RequestBody:requestBody,ExpiresAt:now.Add(24*time.Hour),CreatedAt:now,UpdatedAt:now}, nil
}
func (r *IdempotencyRecord) IsExpired() bool {return time.Now().UTC().After(r.ExpiresAt)}
