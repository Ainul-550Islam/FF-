package storage

import (
    "context"
    "database/sql"
    "fmt"
    "os"
    "time"

    _ "github.com/lib/pq"
    _ "github.com/mattn/go-sqlite3"
)

// Real replica implementation with actual DB connections

func OpenPrimaryDatabase(databaseURL, driver string) (*sql.DB, error) {
    if databaseURL == "" {
        return nil, fmt.Errorf("database URL empty")
    }
    
    db, err := sql.Open(driver, databaseURL)
    if err != nil {
        return nil, err
    }
    
    db.SetMaxOpenConns(20)
    db.SetMaxIdleConns(5)
    db.SetConnMaxLifetime(5 * time.Minute)
    db.SetConnMaxIdleTime(1 * time.Minute)
    
    ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()
    if err := db.PingContext(ctx); err != nil {
        return nil, err
    }
    
    return db, nil
}

func OpenReplicaDatabaseReal(replicaURL, driver string) (*sql.DB, error) {
    if replicaURL == "" {
        return nil, nil
    }
    
    db, err := sql.Open(driver, replicaURL)
    if err != nil {
        return nil, err
    }
    
    // Replica can have more open conns for read scaling
    db.SetMaxOpenConns(30)
    db.SetMaxIdleConns(10)
    db.SetConnMaxLifetime(5 * time.Minute)
    db.SetConnMaxIdleTime(2 * time.Minute)
    
    ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
    defer cancel()
    if err := db.PingContext(ctx); err != nil {
        // Don't fail if replica is down, just log and return nil
        // Primary will be used as fallback
        return nil, nil
    }
    
    return db, nil
}

func NewStoreWithReplica(primaryURL, replicaURL, driver string) (Store, error) {
    primaryDB, err := OpenPrimaryDatabase(primaryURL, driver)
    if err != nil {
        return nil, fmt.Errorf("primary DB open failed: %w", err)
    }
    
    var primaryStore Store
    var replicaStore Store
    
    if driver == "postgres" || driver == "pgsql" || driver == "postgresql" {
        primaryStore = NewPostgresStore(primaryDB)
        
        replicaDB, _ := OpenReplicaDatabaseReal(replicaURL, driver)
        if replicaDB != nil {
            replicaStore = NewPostgresStore(replicaDB)
        }
    } else if driver == "sqlite" || driver == "sqlite3" {
        primaryStore = NewSQLiteStore(primaryDB)
        // SQLite doesn't have replica, but we can use same DB for reads
        if replicaURL != "" && replicaURL != primaryURL {
            replicaDB, _ := OpenReplicaDatabaseReal(replicaURL, driver)
            if replicaDB != nil {
                replicaStore = NewSQLiteStore(replicaDB)
            }
        }
    } else {
        return nil, fmt.Errorf("unsupported driver: %s", driver)
    }
    
    if err := primaryStore.Migrate(context.Background()); err != nil {
        return nil, fmt.Errorf("migration failed: %w", err)
    }
    
    useReplica := os.Getenv("USE_REPLICA") == "true" || replicaStore != nil
    if replicaStore != nil {
        return NewReplicaStore(primaryStore, replicaStore, useReplica), nil
    }
    
    return primaryStore, nil
}
