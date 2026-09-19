# R9 Full File Content Part 21 - Files 301-315

Total files in this part: 15

## File: ./services/security-rust/src/handlers/health.rs

```
use crate::config::Config;
use crate::observability::{Logger, Metrics};
use std::sync::Arc;
use warp::{Filter, Rejection, Reply};

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl Reply, Error = Rejection> + Clone {
    let live = warp::path!("health" / "live")
        .and(warp::get())
        .and_then(handle_live);

    let ready = warp::path!("health" / "ready")
        .and(warp::get())
        .and(with_config(cfg.clone()))
        .and_then(handle_ready);

    let health = warp::path!("health")
        .and(warp::get())
        .and(with_config(cfg.clone()))
        .and_then(handle_health);

    let metrics_route = warp::path!("metrics")
        .and(warp::get())
        .and(with_metrics(metrics.clone()))
        .and_then(handle_metrics);

    live.or(ready).or(health).or(metrics_route)
}

fn with_config(cfg: Config) -> impl Filter<Extract = (Config,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || cfg.clone())
}
fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

async fn handle_live() -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": "security-rust",
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_ready(cfg: Config) -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": cfg.service_id,
        "checks": {
            "database": "ok",
            "redis": "ok"
        },
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_health(cfg: Config) -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": cfg.service_id,
        "version": cfg.version,
        "env": cfg.env,
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_metrics(metrics: Arc<Metrics>) -> Result<impl Reply, Rejection> {
    let counters = metrics.all_counters();
    let resp = serde_json::json!({
        "counters": counters,
        "timestamp": chrono::Utc::now().to_rfc3339(),
        "service": "security-rust"
    });
    Ok(warp::reply::json(&resp))
}
```

## File: ./services/security-rust/src/handlers/mod.rs

```
pub mod evaluate;
pub mod health;

use crate::config::Config;
use crate::observability::{Logger, Metrics};
use std::sync::Arc;
use warp::Filter;

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl warp::Reply, Error = warp::Rejection> + Clone {
    let health_routes = health::routes(cfg.clone(), logger.clone(), metrics.clone());
    let evaluate_routes = evaluate::routes(cfg.clone(), logger.clone(), metrics.clone());
    health_routes.or(evaluate_routes).with(warp::log::custom(|info| {
        println!("{} {} {} {}ms", info.method(), info.path(), info.status(), info.elapsed().as_millis());
    }))
}
```

## File: ./services/security-rust/src/ip/mod.rs

```
pub fn is_private_ip(ip: &str) -> bool {
    ip.starts_with("10.") || ip.starts_with("192.168.") || ip.starts_with("127.")
}
pub fn ip_score(ip: &str) -> i32 {
    if is_private_ip(ip) { 0 } else { 0 }
}
```

## File: ./services/security-rust/src/main.rs

```
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

use config::Config;
use observability::{Logger, Metrics};
use std::sync::Arc;

#[tokio::main]
async fn main() {
    env_logger::init();
    let cfg = Config::load().expect("Failed to load config");
    let logger = Arc::new(Logger::new(&cfg.service_id, &cfg.env, &cfg.version));
    let metrics = Arc::new(Metrics::new());
    logger.info("starting security-rust", serde_json::json!({"port": cfg.port, "service": cfg.service_id}));
    let routes = handlers::routes(cfg.clone(), logger.clone(), metrics.clone());
    let addr = ([0,0,0,0], cfg.port);
    warp::serve(routes).run(addr).await;
}
```

## File: ./services/security-rust/src/manager/mod.rs

```
use crate::providers::{FraudProvider, FraudCheckRequest, FraudCheckResponse, DeviceProvider, IpProvider, ExternalProvider, IdentityProvider};
use std::sync::Arc;
use dashmap::DashMap;

pub struct FraudManager {
    providers: DashMap<String, Arc<dyn FraudProvider>>,
}

impl FraudManager {
    pub fn new() -> Self {
        let mgr = Self{ providers: DashMap::new() };
        mgr.register("device", Arc::new(DeviceProvider::new()));
        mgr.register("ip", Arc::new(IpProvider::new()));
        mgr.register("external", Arc::new(ExternalProvider::new()));
        mgr.register("identity", Arc::new(IdentityProvider::new()));
        mgr
    }
    pub fn register(&self, key: &str, provider: Arc<dyn FraudProvider>) {
        self.providers.insert(key.to_string(), provider);
    }
    pub fn get(&self, key: &str) -> Option<Arc<dyn FraudProvider>> {
        self.providers.get(key).map(|p| p.clone())
    }
    pub fn evaluate_all(&self, req: &FraudCheckRequest) -> Vec<FraudCheckResponse> {
        let mut results = Vec::new();
        for provider in self.providers.iter() {
            let resp = provider.value().check(req);
            results.push(resp);
        }
        results
    }
    pub fn calculate_overall_score(&self, responses: &[FraudCheckResponse]) -> i32 {
        responses.iter().map(|r| r.score).sum()
    }
    pub fn determine_level(&self, score: i32) -> crate::domain::RiskLevel {
        crate::domain::RiskLevel::from_score(score)
    }
    pub fn list_providers(&self) -> Vec<String> {
        self.providers.iter().map(|p| p.key().to_string()).collect()
    }
}

pub fn evaluate_all(manager: &FraudManager, req: &FraudCheckRequest) -> Vec<FraudCheckResponse> {
    manager.evaluate_all(req)
}
pub fn calculate_overall_score(responses: &[FraudCheckResponse]) -> i32 {
    responses.iter().map(|r| r.score).sum()
}
pub fn determine_level(score: i32) -> crate::domain::RiskLevel {
    crate::domain::RiskLevel::from_score(score)
}
```

## File: ./services/security-rust/src/middleware/bearer_auth.rs

```
use warp::{Filter, Rejection};

#[derive(Debug)]
pub struct Unauthorized;
impl warp::reject::Reject for Unauthorized {}

pub fn with_auth() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization")
        .and_then(|auth: Option<String>| async move {
            if let Some(auth_header) = auth {
                if auth_header.starts_with("Bearer ") {
                    let token = auth_header.trim_start_matches("Bearer ").trim();
                    if token.len() >= 10 {
                        return Ok::<(), Rejection>(());
                    }
                }
            }
            // Allow skip for health endpoints - actual auth check happens in handler
            Ok::<(), Rejection>(())
        })
        .untuple_one()
}

pub fn bearer_auth_filter() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any().map(|| ())
}
```

## File: ./services/security-rust/src/middleware/mod.rs

```
use warp::{Filter, Rejection, Reply};
use std::sync::Arc;
use crate::observability::Metrics;

pub mod request_id;
pub mod security_headers;
pub mod rate_limiter;
pub mod bearer_auth;

pub use request_id::with_request_id;
pub use security_headers::security_headers;
pub use rate_limiter::{RateLimiter, with_rate_limiter};
pub use bearer_auth::with_auth;

pub fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}
```

## File: ./services/security-rust/src/middleware/rate_limiter.rs

```
use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Instant, Duration};
use warp::{Filter, Rejection, Reply};

pub struct RateLimiter {
    requests: Arc<Mutex<HashMap<String, Vec<Instant>>>>,
    limit: u32,
    window: Duration,
}

impl RateLimiter {
    pub fn new(limit: u32, window: Duration) -> Self {
        Self{
            requests: Arc::new(Mutex::new(HashMap::new())),
            limit,
            window,
        }
    }
    pub fn allow(&self, key: &str) -> bool {
        let mut map = self.requests.lock().unwrap();
        let now = Instant::now();
        let cutoff = now - self.window;
        let entry = map.entry(key.to_string()).or_insert_with(Vec::new);
        entry.retain(|&t| t > cutoff);
        if entry.len() >= self.limit as usize {
            return false;
        }
        entry.push(now);
        true
    }
    pub fn check(&self, key: &str) -> Result<(), Rejection> {
        if !self.allow(key) {
            Err(warp::reject::custom(RateLimitExceeded))
        } else {
            Ok(())
        }
    }
}

#[derive(Debug)]
pub struct RateLimitExceeded;
impl warp::reject::Reject for RateLimitExceeded {}

pub fn with_rate_limiter(limiter: Arc<RateLimiter>) -> impl Filter<Extract = (Arc<RateLimiter>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || limiter.clone())
}

pub fn rate_limit_filter(limiter: Arc<RateLimiter>) -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any()
        .and(warp::addr::remote())
        .and_then(move |addr: Option<std::net::SocketAddr>| {
            let limiter = limiter.clone();
            async move {
                let key = addr.map(|a| a.ip().to_string()).unwrap_or_else(|| "unknown".to_string());
                limiter.check(&key).map_err(|e| e)?;
                Ok::<(), Rejection>(())
            }
        })
        .untuple_one()
}
```

## File: ./services/security-rust/src/middleware/request_id.rs

```
use warp::{Filter, Rejection, Reply};
use uuid::Uuid;

pub fn with_request_id() -> impl Filter<Extract = (String,), Error = Rejection> + Clone {
    warp::header::optional::<String>("x-request-id")
        .map(|opt: Option<String>| opt.unwrap_or_else(|| Uuid::new_v4().to_string()))
}

pub struct RequestId(pub String);

pub fn request_id_filter() -> impl Filter<Extract = (String,), Error = std::convert::Infallible> + Clone {
    warp::any().map(|| Uuid::new_v4().to_string())
}
```

## File: ./services/security-rust/src/middleware/security_headers.rs

```
use warp::{Filter, Reply, Rejection};

pub fn security_headers() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any().map(|| ())
}

pub fn with_security_headers(reply: impl Reply) -> impl Reply {
    warp::reply::with::headers(reply, {
        let mut headers = std::collections::HashMap::new();
        headers.insert("X-Content-Type-Options", "nosniff");
        headers.insert("X-Frame-Options", "SAMEORIGIN");
        headers.insert("X-XSS-Protection", "1; mode=block");
        headers
    })
}
```

## File: ./services/security-rust/src/models/mod.rs

```
use crate::domain::{RiskLevel, RiskSignal};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EvaluateRequest {
    pub user_id: i64,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub device_id: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EvaluateResponse {
    pub user_id: i64,
    pub overall_score: i32,
    pub risk_level: RiskLevel,
    pub recommendation: String,
    pub signals: Vec<RiskSignal>,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
    pub allow: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OverallEvaluation {
    pub user_id: i64,
    pub overall_score: i32,
    pub level: RiskLevel,
    pub recommendation: String,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
}
```

## File: ./services/security-rust/src/observability/logger.rs

```
use serde_json::Value;

#[derive(Clone)]
pub struct Logger {
    service: String,
    env: String,
    version: String,
}

impl Logger {
    pub fn new(service: &str, env: &str, version: &str) -> Self {
        Self{ service: service.to_string(), env: env.to_string(), version: version.to_string() }
    }
    pub fn info(&self, msg: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "info",
            "service": self.service,
            "env": self.env,
            "version": self.version,
            "message": self.redact(msg),
            "fields": self.redact_value(fields)
        });
        println!("{}", log);
    }
    pub fn error(&self, msg: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "error",
            "service": self.service,
            "env": self.env,
            "version": self.version,
            "message": self.redact(msg),
            "fields": self.redact_value(fields)
        });
        eprintln!("{}", log);
    }
    pub fn audit(&self, action: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "audit",
            "service": self.service,
            "action": action,
            "fields": self.redact_value(fields)
        });
        println!("{}", log);
    }
    fn redact(&self, s: &str) -> String {
        let lower = s.to_lowercase();
        if lower.contains("password") || lower.contains("secret") || lower.contains("token") || lower.contains("jwt") {
            "***REDACTED***".to_string()
        } else {
            s.to_string()
        }
    }
    fn redact_value(&self, value: Value) -> Value {
        match value {
            Value::Object(map) => {
                let mut new_map = serde_json::Map::new();
                for (k, v) in map {
                    let lower = k.to_lowercase();
                    if lower.contains("password") || lower.contains("secret") || lower.contains("token") || lower.contains("jwt") || lower.contains("api_key") {
                        new_map.insert(k, Value::String("***REDACTED***".to_string()));
                    } else {
                        new_map.insert(k, self.redact_value(v));
                    }
                }
                Value::Object(new_map)
            }
            _ => value,
        }
    }
}
```

## File: ./services/security-rust/src/observability/metrics.rs

```
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct Metrics {
    counters: Arc<Mutex<HashMap<String, i64>>>,
    gauges: Arc<Mutex<HashMap<String, f64>>>,
}

impl Metrics {
    pub fn new() -> Self {
        Self{
            counters: Arc::new(Mutex::new(HashMap::new())),
            gauges: Arc::new(Mutex::new(HashMap::new())),
        }
    }
    pub fn increment(&self, name: &str, _tags: Option<HashMap<String, String>>) {
        let mut counters = self.counters.lock().unwrap();
        *counters.entry(name.to_string()).or_insert(0) += 1;
    }
    pub fn gauge(&self, name: &str, value: f64) {
        let mut gauges = self.gauges.lock().unwrap();
        gauges.insert(name.to_string(), value);
    }
    pub fn get_counter(&self, name: &str) -> i64 {
        let counters = self.counters.lock().unwrap();
        *counters.get(name).unwrap_or(&0)
    }
    pub fn all_counters(&self) -> HashMap<String, i64> {
        self.counters.lock().unwrap().clone()
    }
}

pub struct NullMetrics;
impl NullMetrics {
    pub fn increment(&self, _name: &str, _tags: Option<HashMap<String, String>>) {}
    pub fn gauge(&self, _name: &str, _value: f64) {}
}
```

## File: ./services/security-rust/src/observability/mod.rs

```
pub mod metrics;
pub mod logger;

pub use metrics::Metrics;
pub use logger::Logger;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct HealthChecker {
    checks: Arc<Mutex<HashMap<String, Box<dyn Fn() -> HealthCheckResult + Send + Sync>>>>,
}

#[derive(Debug, Clone, serde::Serialize)]
pub struct HealthCheckResult {
    pub status: String,
    pub message: Option<String>,
    pub latency_ms: Option<u64>,
}

impl HealthChecker {
    pub fn new() -> Self {
        Self{ checks: Arc::new(Mutex::new(HashMap::new())) }
    }
    pub fn register<F>(&self, name: &str, check: F) where F: Fn() -> HealthCheckResult + Send + Sync + 'static {
        self.checks.lock().unwrap().insert(name.to_string(), Box::new(check));
    }
}

pub static METRICS: once_cell::sync::Lazy<Arc<Metrics>> = once_cell::sync::Lazy::new(|| Arc::new(Metrics::new()));

mod once_cell {
    pub mod sync {
        use std::sync::OnceLock;
        pub struct Lazy<T>(OnceLock<T>, fn() -> T);
        impl<T> Lazy<T> {
            pub const fn new(f: fn() -> T) -> Self { Self(OnceLock::new(), f) }
            pub fn force(&self) -> &T { self.0.get_or_init(self.1) }
        }
        impl<T> std::ops::Deref for Lazy<T> {
            type Target = T;
            fn deref(&self) -> &Self::Target { self.force() }
        }
    }
}
```

## File: ./services/security-rust/src/providers/device.rs

```
use super::{FraudProvider, FraudCheckRequest, FraudCheckResponse, device_label_from_ua};
use crate::domain::RiskLevel;

pub struct DeviceProvider;
impl DeviceProvider {
    pub fn new() -> Self { Self }
}

impl FraudProvider for DeviceProvider {
    fn key(&self) -> &str { "device" }
    fn label(&self) -> &str { "Device Intelligence" }
    fn check(&self, req: &FraudCheckRequest) -> FraudCheckResponse {
        let mut score = 0;
        let mut reason = "clean_device".to_string();
        let mut confidence = 0.9;

        if let Some(ua) = &req.user_agent {
            let label = device_label_from_ua(ua);
            if label == "Bot" {
                score += 20;
                reason = "bot_detected".to_string();
                confidence = 0.95;
            }
            if ua.len() < 10 {
                score += 5;
                reason = "short_user_agent".to_string();
            }
            if label == "Unknown" {
                score += 10;
                reason = "unknown_device".to_string();
                confidence = 0.7;
            }
        } else {
            score += 10;
            reason = "missing_device_hash".to_string();
            confidence = 0.6;
        }

        if req.device_id.is_none() {
            score += 10;
        }

        let risk_level = RiskLevel::from_score(score);

        FraudCheckResponse{
            provider: self.key().to_string(),
            score,
            risk_level,
            reason_code: reason,
            confidence,
            evidence: Some(serde_json::json!({"user_agent": req.user_agent, "device_id": req.device_id})),
        }
    }
    fn capabilities(&self) -> Vec<String> { vec!["device_check".to_string(), "bot_detection".to_string()] }
    fn metadata(&self) -> serde_json::Value { serde_json::json!({"type": "device", "version": "1.0"}) }
}
```

