package validation
import ("errors"; "regexp"; "strings")
var (
    currencyRegex=regexp.MustCompile(`^[A-Z]{3}$`)
    providerRegex=regexp.MustCompile(`^[a-z_]+$`)
    externalIDRegex=regexp.MustCompile(`^[a-zA-Z0-9_-]+$`)
)
func ValidateAmount(amount int64) error{if amount<=0{return errors.New("amount must be positive")}; if amount>100000000{return errors.New("amount exceeds maximum")}; return nil}
func ValidateCurrency(currency string) error{if !currencyRegex.MatchString(currency){return errors.New("invalid currency format")}; return nil}
func ValidateProvider(provider string) error{if !providerRegex.MatchString(provider){return errors.New("invalid provider format")}; supported:=[]string{"manual","bkash","nagad","rocket"}; for _,s:=range supported{if s==provider{return nil}}; return errors.New("unsupported provider")}
func ValidateExternalID(externalID string) error{if externalID==""{return errors.New("external_id required")}; if len(externalID)<3||len(externalID)>100{return errors.New("external_id length must be 3-100")}; if !externalIDRegex.MatchString(externalID){return errors.New("invalid external_id format")}; return nil}
func ValidateIdempotencyKey(key string) error{if key==""{return errors.New("idempotency key required")}; if len(key)<8||len(key)>100{return errors.New("idempotency key length must be 8-100")}; return nil}
func ValidateEmail(email string) error{if !strings.Contains(email,"@"){return errors.New("invalid email")}; return nil}
