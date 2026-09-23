package config

import (
	"context"
	"database/sql"
	"fmt"
	"time"

	_ "github.com/lib/pq"
	_ "github.com/mattn/go-sqlite3"
)

// OpenDatabase is the single canonical way to open the store connection.
// It merges the hardened driver mapping (postgres/pgsql/postgresql,
// sqlite/sqlite3), connection-pool tuning and both startup strategies that
// previously lived in duplicate copies here and in config.go: the bounded
// context ping and the linear-backoff retry loop.
func OpenDatabase(cfg *Config) (*sql.DB, error) {
	if cfg.DatabaseURL == "" {
		return nil, fmt.Errorf("database URL empty")
	}

	var driver string
	switch cfg.DBDriver {
	case "postgres", "pgsql", "postgresql":
		driver = "postgres"
	case "sqlite", "sqlite3":
		driver = "sqlite3"
	default:
		driver = "postgres"
	}

	db, err := sql.Open(driver, cfg.DatabaseURL)
	if err != nil {
		return nil, fmt.Errorf("failed to open database: %w", err)
	}

	db.SetMaxOpenConns(20)
	db.SetMaxIdleConns(5)
	db.SetConnMaxLifetime(5 * time.Minute)
	db.SetConnMaxIdleTime(1 * time.Minute)

	// Ping with a per-attempt timeout, retried with linear backoff so a
	// database that is still coming up (docker-compose healthcheck race)
	// does not crash the service on boot.
	var lastErr error
	for attempt := 0; attempt < 5; attempt++ {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		err := db.PingContext(ctx)
		cancel()
		if err == nil {
			return db, nil
		}
		lastErr = err
		time.Sleep(time.Duration(attempt+1) * 500 * time.Millisecond)
	}
	return nil, fmt.Errorf("ping db after retries: %w", lastErr)
}

// RedactedURL returns the DSN safe for logs (credentials masked).
func (c *Config) RedactedURL() string { return redactURL(c.DatabaseURL) }
