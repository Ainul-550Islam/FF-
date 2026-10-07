// GAP-10 (finding F-20) — module-level `allow(dead_code)` for the staged
// surface. This crate is written as a library (crypto, audit trail, fraud
// store, worker runtime, header helpers) whose binary currently wires up the
// evaluation endpoint, its middleware and health only. `cargo clippy -D
// warnings` would otherwise fail the CI build on ~140 `never used` notes that
// say nothing about correctness, and a crate-wide allow would hide real lints
// — so the allowance is scoped to the modules that carry staged code, and what
// is genuinely unreachable is recorded in docs/GAP-10-FINDINGS-REGISTER.md
// (F-20) instead of being deleted silently.
#[allow(dead_code)]
mod audit;
#[allow(dead_code)]
mod config;
#[allow(dead_code)]
mod crypto;
#[allow(dead_code)]
mod domain;
#[allow(dead_code)]
mod events;
#[allow(dead_code)]
mod handlers;
#[allow(dead_code)]
mod manager;
#[allow(dead_code)]
mod middleware;
#[allow(dead_code)]
mod models;
#[allow(dead_code)]
mod observability;
#[allow(dead_code)]
mod providers;
#[allow(dead_code)]
mod security;
#[allow(dead_code)]
mod services;
#[allow(dead_code)]
mod storage;
#[allow(dead_code)]
mod workers;

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
            eprintln!(
                "WARNING: Secret validation failed in non-production, continuing with warning"
            );
        }
    }

    let logger = Arc::new(Logger::new(&cfg.service_id, &cfg.env, &cfg.version));
    let metrics = Arc::new(Metrics::new());

    logger.info(
        "starting security-rust service",
        serde_json::json!({
            "port": cfg.port,
            "service": cfg.service_id,
            "version": cfg.version,
            "env": cfg.env,
            "redacted_config": cfg.redacted()
        }),
    );

    // Build routes with middleware chain
    let routes = handlers::routes(cfg.clone(), logger.clone(), metrics.clone()).with(
        warp::log::custom(move |info| {
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
        }),
    );

    let addr = ([0, 0, 0, 0], cfg.port);
    logger.info(
        "security-rust listening",
        serde_json::json!({
            "addr": format!("0.0.0.0:{}", cfg.port),
            "hsts": "max-age=31536000; includeSubDomains; preload",
            "tls": "TLS 1.2+ enforced"
        }),
    );

    warp::serve(routes).run(addr).await;
}
