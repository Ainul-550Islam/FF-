package idempotency

import (
    "context"
    "crypto/sha256"
    "encoding/json"
    "fmt"
    "sync"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/domain"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/storage"
    "github.com/google/uuid"
)

type Service struct {
    store     storage.Store
    logger    *observability.Logger
    metrics   observability.Metrics
    mu        sync.RWMutex
    inFlight  map[string]*sync.Mutex
}

type IdempotencyKey struct {
    Key         string
    UserID      int64
    Operation   string
    Provider    string
    Fingerprint string
}

type CheckResult struct {
    Exists          bool
    Record          *domain.IdempotencyRecord
    IsExpired       bool
    FingerprintMatch bool
    SameUser        bool
}

func NewService(store storage.Store, logger *observability.Logger, metrics observability.Metrics) *Service {
    return &Service{
        store:    store,
        logger:   logger,
        metrics:  metrics,
        inFlight: make(map[string]*sync.Mutex),
    }
}

func (s *Service) GenerateKey() string { return uuid.New().String() }

func (s *Service) HashRequest(data interface{}) (string, error) {
    b, err := json.Marshal(data)
    if err != nil {
        return "", err
    }
    h := sha256.Sum256(b)
    return fmt.Sprintf("%x", h), nil
}

func (s *Service) GenerateFingerprint(userID int64, operation, provider string, requestBody map[string]interface{}) (string, error) {
    // Include user scope, operation scope, provider scope
    composite := map[string]interface{}{
        "user_id":   userID,
        "operation": operation,
        "provider":  provider,
        "body":      requestBody,
    }
    return s.HashRequest(composite)
}

func (s *Service) getOrCreateLock(key string) *sync.Mutex {
    s.mu.Lock()
    defer s.mu.Unlock()
    if mu, ok := s.inFlight[key]; ok {
        return mu
    }
    mu := &sync.Mutex{}
    s.inFlight[key] = mu
    return mu
}

func (s *Service) Check(ctx context.Context, key string, requestBody map[string]interface{}) (*domain.IdempotencyRecord, error) {
    record, err := s.store.GetIdempotency(ctx, key)
    if err != nil {
        return nil, nil
    }
    fp, err := s.HashRequest(requestBody)
    if err != nil {
        return nil, err
    }
    if record.Fingerprint != fp {
        return nil, fmt.Errorf("idempotency key exists with different fingerprint")
    }
    if record.ExpiresAt.Before(time.Now()) {
        s.store.DeleteIdempotency(ctx, key)
        return nil, nil
    }
    return record, nil
}

func (s *Service) CheckWithScopes(ctx context.Context, key string, userID int64, operation, provider string, requestBody map[string]interface{}) (*CheckResult, error) {
    // Concurrent protection - lock per key
    mu := s.getOrCreateLock(key)
    mu.Lock()
    defer mu.Unlock()

    record, err := s.store.GetIdempotency(ctx, key)
    if err != nil {
        // Not found - no existing record
        return &CheckResult{Exists: false}, nil
    }

    if record == nil {
        return &CheckResult{Exists: false}, nil
    }

    // Check expiration
    if record.ExpiresAt.Before(time.Now()) {
        s.store.DeleteIdempotency(ctx, key)
        return &CheckResult{Exists: false, IsExpired: true}, nil
    }

    // Generate fingerprint with scopes
    fp, err := s.GenerateFingerprint(userID, operation, provider, requestBody)
    if err != nil {
        return nil, err
    }

    fingerprintMatch := record.Fingerprint == fp
    sameUser := true
    if record.UserID != nil && *record.UserID != userID {
        sameUser = false
    }

    // If fingerprint doesn't match, it's a different request with same key - reject
    if !fingerprintMatch {
        return &CheckResult{
            Exists:          true,
            Record:          record,
            FingerprintMatch: false,
            SameUser:        sameUser,
        }, fmt.Errorf("idempotency key exists with different fingerprint - possible duplicate with modified body")
    }

    if s.metrics != nil {
        s.metrics.Increment("idempotency_hit", map[string]string{"operation": operation, "provider": provider})
    }

    return &CheckResult{
        Exists:          true,
        Record:          record,
        FingerprintMatch: true,
        SameUser:        sameUser,
    }, nil
}

func (s *Service) Save(ctx context.Context, key, operation string, requestBody, responseBody map[string]interface{}, statusCode int) error {
    fp, err := s.HashRequest(requestBody)
    if err != nil {
        return err
    }
    record := &domain.IdempotencyRecord{
        Key:          key,
        Fingerprint:  fp,
        Operation:    operation,
        RequestBody:  requestBody,
        ResponseBody: responseBody,
        StatusCode:   &statusCode,
        ExpiresAt:    time.Now().Add(24 * time.Hour),
        CreatedAt:    time.Now(),
        UpdatedAt:    time.Now(),
    }
    return s.store.SetIdempotency(ctx, record)
}

func (s *Service) SaveWithScopes(ctx context.Context, key string, userID int64, operation, provider string, requestBody, responseBody map[string]interface{}, statusCode int) error {
    mu := s.getOrCreateLock(key)
    mu.Lock()
    defer mu.Unlock()

    fp, err := s.GenerateFingerprint(userID, operation, provider, requestBody)
    if err != nil {
        return err
    }

    record := &domain.IdempotencyRecord{
        Key:          key,
        Fingerprint:  fp,
        Operation:    operation,
        UserID:       &userID,
        RequestBody:  requestBody,
        ResponseBody: responseBody,
        StatusCode:   &statusCode,
        ExpiresAt:    time.Now().Add(24 * time.Hour),
        CreatedAt:    time.Now(),
        UpdatedAt:    time.Now(),
    }

    if s.logger != nil {
        s.logger.Info("idempotency saved", map[string]interface{}{
            "key":       key,
            "operation": operation,
            "provider":  provider,
            "user_id":   userID,
        })
    }

    return s.store.SetIdempotency(ctx, record)
}

func (s *Service) Delete(ctx context.Context, key string) error {
    return s.store.DeleteIdempotency(ctx, key)
}

func (s *Service) Cleanup(ctx context.Context) error {
    return s.store.CleanupIdempotency(ctx)
}

// Test helper: simulate 10 concurrent identical requests
func (s *Service) TestConcurrentIdempotency(ctx context.Context, key string, requestBody map[string]interface{}) (int, error) {
    // This would be used in tests to verify exactly one payment side effect
    var wg sync.WaitGroup
    results := make([]*domain.IdempotencyRecord, 10)
    errors := make([]error, 10)

    for i := 0; i < 10; i++ {
        wg.Add(1)
        go func(idx int) {
            defer wg.Done()
            result, err := s.Check(ctx, key, requestBody)
            results[idx] = result
            errors[idx] = err
        }(i)
    }
    wg.Wait()

    // Count how many got existing record vs new
    existingCount := 0
    for _, r := range results {
        if r != nil {
            existingCount++
        }
    }

    return existingCount, nil
}

func (s *Service) GetRecord(ctx context.Context, key string) (*domain.IdempotencyRecord, error) {
    return s.store.GetIdempotency(ctx, key)
}
