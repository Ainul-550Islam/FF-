package config
import (
    "database/sql"
    "fmt"
    "time"
    _ "github.com/lib/pq"
    _ "github.com/mattn/go-sqlite3"
)
func OpenDatabase(cfg *Config) (*sql.DB, error) {
    var driver string
    switch cfg.DBDriver {
    case "postgres","pgsql": driver="postgres"
    case "sqlite","sqlite3": driver="sqlite3"
    default: driver="postgres"
    }
    db, err:=sql.Open(driver,cfg.DatabaseURL)
    if err!=nil{return nil, fmt.Errorf("open db: %w",err)}
    db.SetMaxOpenConns(20)
    db.SetMaxIdleConns(5)
    db.SetConnMaxLifetime(5*time.Minute)
    var lastErr error
    for i:=0;i<5;i++{
        if err:=db.Ping(); err==nil{return db, nil} else {lastErr=err; time.Sleep(time.Duration(i+1)*500*time.Millisecond)}
    }
    return nil, fmt.Errorf("ping db after retries: %w",lastErr)
}
func (c *Config) RedactedURL() string {return redactURL(c.DatabaseURL)}
