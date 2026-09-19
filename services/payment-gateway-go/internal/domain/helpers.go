package domain
import "time"
func GenerateKeyTime() time.Time {return time.Now().Add(24*time.Hour)}
