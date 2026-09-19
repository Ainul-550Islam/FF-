package utils
import "errors"
func ValidateAmount(amount int64) error{if amount<=0{return errors.New("amount must be positive")}; return nil}
func ValidateCurrency(currency string) error{if len(currency)!=3{return errors.New("currency must be 3 chars")}; return nil}
