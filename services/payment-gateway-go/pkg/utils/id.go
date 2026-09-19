package utils
import "github.com/google/uuid"
func GenerateID() string {return uuid.New().String()}
func GenerateExternalID() string {return uuid.New().String()}
func GenerateIdempotencyKey() string {return uuid.New().String()}
