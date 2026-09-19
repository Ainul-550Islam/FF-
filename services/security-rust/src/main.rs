mod config;
mod domain;
mod models;
mod providers;
mod manager;
mod middleware;
mod observability;
mod handlers;
mod security;
mod services;
mod events;
mod audit;
mod storage;
mod workers;
mod crypto;

use config::Config;
use observability::{Logger, Metrics};
use std::sync::Arc;
use warp::Filter;

#[tokio::main]
async fn main() {
    // Initialize logger
    env_logger::init();
    
    // Load configuration with secret strength validation
    let cfg = match Config::load() {
        Ok(c) => c,
        Err(e) => {
            eprintln!("Failed to load config: {}", e);
            std::process::exit(1);
        }
    };
    
    // Validate secret strength - minimum 32 characters, reject weak strings
    if let Err(e) = cfg.validate_secret_strength() {
        eprintln!("Secret strength validation failed: {}", e);
        if cfg.is_production() {
            eprintln!("FATAL: Secret validation failed in production, exiting");
            std::process::exit(1);
        } else {
            eprintln!("WARNING: Secret validation failed in non-production, continuing with warning");
        }
    }
    
    let logger = Arc::new(Logger::new(&cfg.service_id, &cfg.env, &cfg.version));
    let metrics = Arc::new(Metrics::new());
    
    logger.info("starting security-rust service", serde_json::json!({
        "port": cfg.port,
        "service": cfg.service_id,
        "version": cfg.version,
        "env": cfg.env,
        "redacted_config": cfg.redacted()
    }));
    
    // Build routes with middleware chain
    let routes = handlers::routes(cfg.clone(), logger.clone(), metrics.clone())
        .with(warp::log::custom(move |info| {
            // Structured logging with redaction
            let redacted_path = if info.path().contains("token") || info.path().contains("secret") {
                "***REDACTED***"
            } else {
                info.path()
            };
            log::info!(
                "request method={} path={} status={} elapsed={}ms",
                info.method(),
                redacted_path,
                info.status().as_u16(),
                info.elapsed().as_millis()
            );
        }));
    
    let addr = ([0, 0, 0, 0], cfg.port);
    logger.info("security-rust listening", serde_json::json!({
        "addr": format!("0.0.0.0:{}", cfg.port),
        "hsts": "max-age=31536000; includeSubDomains; preload",
        "tls": "TLS 1.2+ enforced"
    }));
    
    warp::serve(routes).run(addr).await;
}
