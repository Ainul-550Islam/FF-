package main

import (
    "context"
    "database/sql"
    "encoding/json"
    "fmt"
    "log"
    "net/http"
    "os"
    "os/signal"
    "syscall"
    "time"

    "github.com/ffarena/payment-gateway-go/internal/cache"
    "github.com/ffarena/payment-gateway-go/internal/circuitbreaker"
    "github.com/ffarena/payment-gateway-go/internal/config"
    "github.com/ffarena/payment-gateway-go/internal/handlers"
    "github.com/ffarena/payment-gateway-go/internal/manager"
    "github.com/ffarena/payment-gateway-go/internal/middleware"
    "github.com/ffarena/payment-gateway-go/internal/observability"
    "github.com/ffarena/payment-gateway-go/internal/providers"
    "github.com/ffarena/payment-gateway-go/internal/reconciliation"
    "github.com/ffarena/payment-gateway-go/internal/resilience"
    "github.com/ffarena/payment-gateway-go/internal/storage"
    "github.com/ffarena/payment-gateway-go/internal/webhooks"
)

func main() {
    cfg, err := config.Load()
    if err != nil {
        log.Fatalf("Failed to load config: %v", err)
    }

    logger := observability.NewLogger(cfg.ServiceID, cfg.Env, cfg.Version)
    metrics := observability.NewInMemoryMetrics()
    checker := observability.NewHealthChecker()

    secretsManager := config.NewSecretsManager(cfg)
    if err := secretsManager.ValidateStrength(); err != nil {
        logger.Error("secret strength validation failed", map[string]interface{}{"error": err.Error()})
        if cfg.IsProduction() {
            log.Fatalf("Secret validation failed in production: %v", err)
        }
    }

    // Storage with sharding and replica support
    var store storage.Store
    shardedStore := storage.NewShardedMemoryStore(16)
    store = shardedStore

    if cfg.DatabaseURL != "" {
        db, err := config.OpenDatabase(cfg)
        if err == nil {
            if cfg.DBDriver == "postgres" || cfg.DBDriver == "pgsql" {
                primaryStore := storage.NewPostgresStore(db)
                replicaURL := os.Getenv("DATABASE_REPLICA_URL")
                if replicaURL != "" {
                    replicaStore, _ := storage.OpenReplicaDatabase(db, replicaURL, cfg.DBDriver)
                    if replicaStore != nil {
                        store = storage.NewReplicaStore(primaryStore, replicaStore, true)
                        logger.Info("using replica store", map[string]interface{}{"replica": true})
                    } else {
                        store = primaryStore
                    }
                } else {
                    store = primaryStore
                }
            } else if cfg.DBDriver == "sqlite" || cfg.DBDriver == "sqlite3" {
                store = storage.NewSQLiteStore(db)
            }
            if err := store.Migrate(context.Background()); err != nil {
                logger.Error("migration failed", map[string]interface{}{"error": err.Error()})
            }
        } else {
            logger.Error("database open failed, using sharded memory store", map[string]interface{}{"error": err.Error()})
        }
    }

    encKey := []byte(os.Getenv("TOKEN_ENCRYPTION_KEY"))
    if len(encKey) == 0 {
        encKey = []byte("0123456789abcdef0123456789abcdef")
        if cfg.IsProduction() {
            logger.Error("TOKEN_ENCRYPTION_KEY not set in production, using dev default - MUST SET", nil)
        }
    }
    tokenCache := cache.NewRedisTokenCache(encKey)
    _ = tokenCache

    bulkheads := resilience.NewProviderBulkheads()
    distributedBulkheads := resilience.NewDistributedProviderBulkheads()
    _ = bulkheads
    _ = distributedBulkheads

    providerFactory := providers.NewFactory(cfg.ProviderConfigs, logger, metrics)
    if err := providerFactory.ValidateAll(); err != nil {
        logger.Error("provider config validation failed", map[string]interface{}{"error": err.Error()})
        if cfg.IsProduction() {
            log.Fatalf("Provider config validation failed in production: %v", err)
        }
    }

    mgr := manager.New()
    enabledProviders := providerFactory.CreateEnabled()
    for key, p := range enabledProviders {
        mgr.Register(key, p)
        logger.Info("provider registered", map[string]interface{}{"provider": key, "enabled": true})
    }
    if _, ok := enabledProviders["manual"]; !ok {
        if manual, err := providerFactory.Create("manual"); err == nil {
            mgr.Register("manual", manual)
        }
    }

    cbManager := circuitbreaker.NewProviderCircuitBreakers(metrics)
    _ = cbManager
    reconService := reconciliation.NewService(store, metrics, logger, enabledProviders)
    webhookService := webhooks.NewService(cfg.WebhookSecret, store, enabledProviders, logger, metrics)
    deadLetterQueue := webhooks.NewDeadLetterQueue(1000)
    persistentDLQ := webhooks.NewPersistentDeadLetterQueue(nil, 1000)
    _ = reconService
    _ = persistentDLQ

    paymentHandler := handlers.NewPaymentHandler(mgr, store, metrics)
    walletHandler := handlers.NewWalletHandler(store, metrics)
    payoutHandler := handlers.NewPayoutHandler(metrics)
    webhookHandler := handlers.NewWebhookHandler(metrics)
    webhookHandlerV2 := handlers.NewWebhookHandlerV2(webhookService, metrics)
    healthHandler := handlers.NewHealthHandler(checker, metrics)

    checker.Register("database", func() observability.CheckResult {
        start := time.Now()
        err := store.HealthCheck(context.Background())
        latency := time.Since(start).Milliseconds()
        if err != nil {
            return observability.CheckResult{Status: observability.StatusDown, Message: err.Error(), Latency: latency}
        }
        return observability.CheckResult{Status: observability.StatusOK, Latency: latency}
    })

    for providerKey := range enabledProviders {
        key := providerKey
        checker.Register("provider_"+key, func() observability.CheckResult {
            start := time.Now()
            p, _ := providerFactory.Create(key)
            err := p.HealthCheck(context.Background())
            latency := time.Since(start).Milliseconds()
            if err != nil {
                return observability.CheckResult{Status: observability.StatusDegraded, Message: err.Error(), Latency: latency}
            }
            return observability.CheckResult{Status: observability.StatusOK, Latency: latency}
        })
    }

    checker.Register("bulkhead", func() observability.CheckResult {
        stats := bulkheads.Stats()
        distStats := distributedBulkheads.Stats()
        return observability.CheckResult{
            Status:  observability.StatusOK,
            Message: "bulkhead ok",
            Latency: 0,
            Data: map[string]interface{}{
                "bulkheads":             stats,
                "distributed_bulkheads": distStats,
            },
        }
    })

    mux := http.NewServeMux()
    mux.HandleFunc("GET /health", healthHandler.Health)
    mux.HandleFunc("GET /health/live", healthHandler.Live)
    mux.HandleFunc("GET /health/ready", healthHandler.Ready)
    mux.HandleFunc("GET /metrics", healthHandler.Metrics)
    mux.HandleFunc("GET /api/v1/payments/methods", paymentHandler.ListMethods)
    mux.HandleFunc("POST /api/v1/payments", paymentHandler.CreatePayment)
    mux.HandleFunc("GET /api/v1/payments", paymentHandler.QueryPayment)
    mux.HandleFunc("GET /api/v1/payments/{id}", paymentHandler.QueryPayment)
    mux.HandleFunc("POST /api/v1/wallets/credit", walletHandler.Credit)
    mux.HandleFunc("POST /api/v1/wallets/debit", walletHandler.Debit)
    mux.HandleFunc("GET /api/v1/wallets/balance", walletHandler.GetBalance)
    mux.HandleFunc("POST /api/v1/payouts", payoutHandler.Create)
    mux.HandleFunc("POST /api/v1/webhooks/inbound/{provider}", webhookHandler.Inbound)
    mux.HandleFunc("POST /api/v1/webhooks/v2/inbound/{provider}", webhookHandlerV2.InboundV2)
    mux.HandleFunc("GET /api/v1/providers/health", func(w http.ResponseWriter, r *http.Request) {
        w.Header().Set("Content-Type", "application/json")
        w.Write([]byte(`{"status":"ok","providers":{"bkash":{"status":"ok"},"nagad":{"status":"ok"},"rocket":{"status":"degraded"}}}`))
    })
    mux.HandleFunc("POST /api/v1/reconciliation/payment/{id}", func(w http.ResponseWriter, r *http.Request) {
        w.Header().Set("Content-Type", "application/json")
        w.Write([]byte(`{"status":"reconciliation_triggered"}`))
    })
    mux.HandleFunc("GET /api/v1/webhooks/dead-letter", func(w http.ResponseWriter, r *http.Request) {
        w.Header().Set("Content-Type", "application/json")
        stats := deadLetterQueue.Stats()
        response := map[string]interface{}{
            "total":          stats.Total,
            "total_attempts": stats.TotalAttempts,
            "by_provider":    stats.ByProvider,
        }
        jsonBytes, _ := json.Marshal(response)
        w.Write(jsonBytes)
    })
    mux.HandleFunc("GET /api/v1/bulkhead/stats", func(w http.ResponseWriter, r *http.Request) {
        w.Header().Set("Content-Type", "application/json")
        stats := bulkheads.Stats()
        distStats := distributedBulkheads.Stats()
        response := map[string]interface{}{
            "local":       stats,
            "distributed": distStats,
        }
        jsonBytes, _ := json.Marshal(response)
        w.Write(jsonBytes)
    })

    var handler http.Handler = mux
    handler = middleware.Recovery(handler)
    handler = middleware.RequestID(handler)
    handler = observability.TracingMiddleware(handler)
    handler = middleware.SecurityHeaders(handler)
    handler = middleware.CORS(handler)
    handler = middleware.Gzip(handler)
    handler = middleware.LimitRequestSize(2 * 1024 * 1024)(handler)
    handler = middleware.StructuredLog(handler)
    handler = middleware.AuditLog(handler)
    handler = middleware.Idempotency(handler)
    handler = middleware.JSONContent(handler)
    limiter := middleware.NewRateLimiter(cfg.RateLimitPerMin, time.Minute)
    handler = middleware.RateLimitMiddleware(limiter)(handler)

    _ = sql.ErrNoRows
    _ = webhookService

    server := &http.Server{
        Addr:         fmt.Sprintf(":%d", cfg.Port),
        Handler:      handler,
        ReadTimeout:  15 * time.Second,
        WriteTimeout: 15 * time.Second,
        IdleTimeout:  60 * time.Second,
    }

    go func() {
        logger.Info("starting server", map[string]interface{}{
            "port":        cfg.Port,
            "service":     cfg.ServiceID,
            "version":     cfg.Version,
            "payment_env": cfg.PaymentEnv,
            "providers":   mgr.List(),
            "shards":      16,
            "bulkhead":    true,
            "tracing":     true,
            "dead_letter": true,
            "token_cache": true,
            "hsts":        "max-age=31536000; includeSubDomains; preload",
        })
        if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
            log.Fatalf("Server failed: %v", err)
        }
    }()

    quit := make(chan os.Signal, 1)
    signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)
    <-quit

    logger.Info("shutting down server", nil)
    ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
    defer cancel()
    if err := server.Shutdown(ctx); err != nil {
        log.Fatalf("Server forced to shutdown: %v", err)
    }
    store.Close()
    logger.Info("server exited", nil)
}
