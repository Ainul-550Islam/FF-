package testing
import "github.com/google/uuid"
func GenerateExternalID() string {return uuid.New().String()}
func GenerateIdempotencyKey() string {return uuid.New().String()}
func GenerateProviderReference() string {return uuid.New().String()}
