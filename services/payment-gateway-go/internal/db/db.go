package db

import (
    "context"
    "database/sql"
    "fmt"
    "os"
    "strconv"
    "strings"
    "time"

    _ "github.com/lib/pq"
    _ "github.com/mattn/go-sqlite3"
)

// Database layer with enforced parameterized queries
// - $1, $2 for PostgreSQL
// - ? for SQLite
// - No string concatenation with user input
// - All queries use placeholders

type DB struct {
    primary *sql.DB
    replica *sql.DB
    driver  string
}

type Config struct {
    PrimaryURL   string
    ReplicaURL   string
    Driver       string
    MaxOpenConns int
    MaxIdleConns int
    MaxLifetime  time.Duration
    MaxIdleTime  time.Duration
}

func LoadConfig() Config {
    return Config{
        PrimaryURL:   getEnv("DATABASE_URL", ""),
        ReplicaURL:   getEnv("DATABASE_REPLICA_URL", ""),
        Driver:       getEnv("DB_DRIVER", "postgres"),
        MaxOpenConns: getEnvInt("DB_MAX_OPEN_CONNS", 20),
        MaxIdleConns: getEnvInt("DB_MAX_IDLE_CONNS", 5),
        MaxLifetime:  time.Duration(getEnvInt("DB_CONN_MAX_LIFETIME_MIN", 5)) * time.Minute,
        MaxIdleTime:  time.Duration(getEnvInt("DB_CONN_MAX_IDLE_TIME_MIN", 1)) * time.Minute,
    }
}

func New(cfg Config) (*DB, error) {
    if cfg.PrimaryURL == "" {
        return nil, fmt.Errorf("DATABASE_URL must be set")
    }

    driver := normalizeDriver(cfg.Driver)

    primaryDB, err := openWithConfig(cfg.PrimaryURL, driver, cfg)
    if err != nil {
        return nil, fmt.Errorf("failed to open primary DB: %w", err)
    }

    var replicaDB *sql.DB
    if cfg.ReplicaURL != "" && cfg.ReplicaURL != cfg.PrimaryURL {
        replicaCfg := cfg
        replicaCfg.MaxOpenConns = getEnvInt("DB_REPLICA_MAX_OPEN_CONNS", 30)
        replicaCfg.MaxIdleConns = getEnvInt("DB_REPLICA_MAX_IDLE_CONNS", 10)
        replicaDB, _ = openWithConfig(cfg.ReplicaURL, driver, replicaCfg)
        // Don't fail if replica is down, primary will be used
    }

    return &DB{
        primary: primaryDB,
        replica: replicaDB,
        driver:  driver,
    }, nil
}

func openWithConfig(url, driver string, cfg Config) (*sql.DB, error) {
    db, err := sql.Open(driver, url)
    if err != nil {
        return nil, err
    }

    db.SetMaxOpenConns(cfg.MaxOpenConns)
    db.SetMaxIdleConns(cfg.MaxIdleConns)
    db.SetConnMaxLifetime(cfg.MaxLifetime)
    db.SetConnMaxIdleTime(cfg.MaxIdleTime)

    ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()
    if err := db.PingContext(ctx); err != nil {
        return nil, err
    }

    return db, nil
}

func normalizeDriver(driver string) string {
    switch strings.ToLower(driver) {
    case "postgres", "pgsql", "postgresql":
        return "postgres"
    case "sqlite", "sqlite3":
        return "sqlite3"
    default:
        return driver
    }
}

func (d *DB) Primary() *sql.DB {
    return d.primary
}

func (d *DB) Replica() *sql.DB {
    if d.replica != nil {
        return d.replica
    }
    return d.primary
}

func (d *DB) ReadDB() *sql.DB {
    if d.replica != nil && os.Getenv("USE_REPLICA") == "true" {
        return d.replica
    }
    return d.primary
}

func (d *DB) WriteDB() *sql.DB {
    return d.primary
}

func (d *DB) Close() error {
    if d.primary != nil {
        if err := d.primary.Close(); err != nil {
            return err
        }
    }
    if d.replica != nil {
        if err := d.replica.Close(); err != nil {
            return err
        }
    }
    return nil
}

func (d *DB) HealthCheck(ctx context.Context) error {
    ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
    defer cancel()

    if err := d.primary.PingContext(ctx); err != nil {
        return fmt.Errorf("primary DB health check failed: %w", err)
    }

    if d.replica != nil {
        ctx2, cancel2 := context.WithTimeout(context.Background(), 2*time.Second)
        defer cancel2()
        if err := d.replica.PingContext(ctx2); err != nil {
            // Log but don't fail overall if replica is down
            // Primary will be used as fallback
            fmt.Printf("Replica DB health check failed, falling back to primary: %v\n", err)
        }
    }

    return nil
}

// Parameterized query helpers - enforce $1,$2 for Postgres and ? for SQLite

func (d *DB) QueryRowContext(ctx context.Context, query string, args ...interface{}) *sql.Row {
    // Query is already parameterized with $1,$2 or ?
    // No string concatenation with user input allowed
    return d.ReadDB().QueryRowContext(ctx, query, args...)
}

func (d *DB) QueryContext(ctx context.Context, query string, args ...interface{}) (*sql.Rows, error) {
    return d.ReadDB().QueryContext(ctx, query, args...)
}

func (d *DB) ExecContext(ctx context.Context, query string, args ...interface{}) (sql.Result, error) {
    return d.WriteDB().ExecContext(ctx, query, args...)
}

// Transaction with proper handling - no hold transaction open during slow external HTTP unless required
// Preferred: 1 persist pending, 2 call provider, 3 persist result transactionally, 4 process wallet/ledger atomically

func (d *DB) WithTransaction(ctx context.Context, fn func(*sql.Tx) error) error {
    tx, err := d.WriteDB().BeginTx(ctx, &sql.TxOptions{
        Isolation: sql.LevelReadCommitted,
    })
    if err != nil {
        return err
    }

    defer func() {
        if p := recover(); p != nil {
            tx.Rollback()
            panic(p)
        }
    }()

    if err := fn(tx); err != nil {
        tx.Rollback()
        return err
    }

    return tx.Commit()
}

// Examples of parameterized queries - PostgreSQL uses $1,$2, SQLite uses ?

// PostgreSQL examples:
const (
    // Payments
    PGCreatePayment = `INSERT INTO payments (id, user_id, wallet_id, provider, external_id, amount_minor, currency, status, idempotency_key, created_at, updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)`
    PGGetPaymentByID = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE id=$1`
    PGGetPaymentByExternalID = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE external_id=$1`
    PGGetPaymentByIdempotencyKey = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE idempotency_key=$1`
    PGUpdatePaymentStatus = `UPDATE payments SET status=$1, provider_reference=$2, updated_at=$3 WHERE id=$4`
    PGListPaymentsByUser = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE user_id=$1 ORDER BY created_at DESC LIMIT $2 OFFSET $3`

    // Wallets
    PGCreateWallet = `INSERT INTO wallets (id, user_id, currency, balance_minor, is_locked, created_at, updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7) ON CONFLICT (user_id, currency) DO NOTHING`
    PGGetWalletByID = `SELECT id, user_id, currency, balance_minor, is_locked, created_at, updated_at FROM wallets WHERE id=$1`
    PGGetWalletByUserAndCurrency = `SELECT id, user_id, currency, balance_minor, is_locked, created_at, updated_at FROM wallets WHERE user_id=$1 AND currency=$2`
    PGUpdateWalletBalance = `UPDATE wallets SET balance_minor=$1, updated_at=$2 WHERE id=$3`
    PGLockWalletForUpdate = `SELECT id, user_id, currency, balance_minor, is_locked FROM wallets WHERE user_id=$1 AND currency=$2 FOR UPDATE`

    // Ledger
    PGCreateLedgerEntry = `INSERT INTO ledger_entries (id, wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, metadata, created_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)`
    PGListLedgerByWallet = `SELECT id, wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, created_at FROM ledger_entries WHERE wallet_id=$1 ORDER BY created_at DESC, id DESC LIMIT $2 OFFSET $3`
    PGGetLedgerByIdempotencyKey = `SELECT id, wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, created_at FROM ledger_entries WHERE idempotency_key=$1`
    PGCalculateBalance = `SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount_minor ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN direction='debit' THEN amount_minor ELSE 0 END),0) as debits FROM ledger_entries WHERE wallet_id=$1`

    // Webhooks
    PGCreateWebhookEvent = `INSERT INTO webhook_events (id, provider, event_type, event_id, payload, signature, state, attempts, created_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)`
    PGGetWebhookByEventID = `SELECT id, provider, event_type, event_id, payload, signature, state, attempts, created_at FROM webhook_events WHERE event_id=$1`
    PGGetWebhookByProviderRef = `SELECT id, provider, event_type, event_id, payload, signature, state, attempts, created_at FROM webhook_events WHERE provider=$1 AND event_id=$2`
    PGUpdateWebhookState = `UPDATE webhook_events SET state=$1, attempts=$2, last_error=$3, processed_at=$4 WHERE id=$5`

    // Dead letter
    PGCreateDeadLetter = `INSERT INTO webhook_dead_letters (id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10) ON CONFLICT (id) DO UPDATE SET error=EXCLUDED.error, attempts=EXCLUDED.attempts, last_failed=EXCLUDED.last_failed, next_retry=EXCLUDED.next_retry`
    PGGetDeadLetterByID = `SELECT id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry FROM webhook_dead_letters WHERE id=$1`
    PGListDeadLettersDue = `SELECT id, provider, event_id, payload, raw_body, error, attempts, first_failed, last_failed, next_retry FROM webhook_dead_letters WHERE next_retry IS NOT NULL AND next_retry <= NOW() ORDER BY next_retry ASC LIMIT $1`
)

// SQLite examples (using ? placeholder):
const (
    SQLiteCreatePayment = `INSERT INTO payments (id, user_id, wallet_id, provider, external_id, amount_minor, currency, status, idempotency_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)`
    SQLiteGetPaymentByID = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE id=?`
    SQLiteGetPaymentByExternalID = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE external_id=?`
    SQLiteUpdatePaymentStatus = `UPDATE payments SET status=?, provider_reference=?, updated_at=? WHERE id=?`
    SQLiteListPaymentsByUser = `SELECT id, user_id, wallet_id, provider, external_id, provider_reference, amount_minor, currency, status, idempotency_key, created_at, updated_at FROM payments WHERE user_id=? ORDER BY created_at DESC LIMIT ? OFFSET ?`

    SQLiteCreateWallet = `INSERT INTO wallets (id, user_id, currency, balance_minor, is_locked, created_at, updated_at) VALUES (?,?,?,?,?,?,?) ON CONFLICT(user_id, currency) DO NOTHING`
    SQLiteGetWalletByUserAndCurrency = `SELECT id, user_id, currency, balance_minor, is_locked, created_at, updated_at FROM wallets WHERE user_id=? AND currency=?`
    SQLiteUpdateWalletBalance = `UPDATE wallets SET balance_minor=?, updated_at=? WHERE id=?`

    SQLiteCreateLedgerEntry = `INSERT INTO ledger_entries (id, wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, metadata, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)`
    SQLiteListLedgerByWallet = `SELECT id, wallet_id, user_id, direction, amount_minor, balance_after_minor, reference_type, reference_id, idempotency_key, created_at FROM ledger_entries WHERE wallet_id=? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?`
    SQLiteCalculateBalance = `SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount_minor ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN direction='debit' THEN amount_minor ELSE 0 END),0) as debits FROM ledger_entries WHERE wallet_id=?`
)

func getEnv(key, defaultValue string) string {
    if value := os.Getenv(key); value != "" {
        return value
    }
    return defaultValue
}

func getEnvInt(key string, defaultValue int) int {
    if value := os.Getenv(key); value != "" {
        if intValue, err := strconv.Atoi(value); err == nil {
            return intValue
        }
    }
    return defaultValue
}
