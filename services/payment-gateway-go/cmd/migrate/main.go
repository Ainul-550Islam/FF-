package main
import ("context"; "log"; "github.com/ffarena/payment-gateway-go/internal/config"; "github.com/ffarena/payment-gateway-go/internal/storage")
func main(){
    cfg,err:=config.Load()
    if err!=nil{log.Fatalf("Failed to load config: %v",err)}
    db,err:=config.OpenDatabase(cfg)
    if err!=nil{log.Fatalf("Failed to open database: %v",err)}
    var store storage.Store
    if cfg.DBDriver=="postgres"||cfg.DBDriver=="pgsql"{
        store=storage.NewPostgresStore(db)
    } else {
        store=storage.NewSQLiteStore(db)
    }
    if err:=store.Migrate(context.Background()); err!=nil{log.Fatalf("Migration failed: %v",err)}
    log.Println("Migration completed successfully")
}
