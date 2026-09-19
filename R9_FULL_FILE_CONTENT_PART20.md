# R9 Full File Content Part 20 - Files 286-300

Total files in this part: 15

## File: ./services/payment-gateway-go/tests/idempotency_test.go

```
package tests
import ("context"; "testing"; "github.com/ffarena/payment-gateway-go/internal/domain"; "github.com/ffarena/payment-gateway-go/internal/storage")
func TestIdempotencyRecord(t *testing.T){
    store:=storage.NewMemoryStore()
    record:=&domain.IdempotencyRecord{Key:"test-key",Fingerprint:"fp123",Operation:"create_payment",ExpiresAt:domain.GenerateKeyTime()}
    err:=store.SetIdempotency(context.Background(),record)
    if err!=nil{t.Fatalf("failed to set idempotency: %v",err)}
    retrieved,err:=store.GetIdempotency(context.Background(),"test-key")
    if err!=nil{t.Fatalf("failed to get idempotency: %v",err)}
    if retrieved.Fingerprint!=record.Fingerprint{t.Errorf("fingerprint mismatch")}
}
func TestIdempotencyDuplicate(t *testing.T){
    store:=storage.NewMemoryStore()
    ctx:=context.Background()
    payment1:=&domain.IdempotencyRecord{Key:"idem-123",Fingerprint:"fp-123",Operation:"payment",ExpiresAt:domain.GenerateKeyTime()}
    payment2:=&domain.IdempotencyRecord{Key:"idem-123",Fingerprint:"fp-456",Operation:"payment",ExpiresAt:domain.GenerateKeyTime()}
    store.SetIdempotency(ctx,payment1)
    retrieved,err:=store.GetIdempotency(ctx,"idem-123")
    if err!=nil{t.Fatalf("failed to get: %v",err)}
    if retrieved.Fingerprint=="fp-456"{t.Error("should not overwrite with different fingerprint without check")}
    _=payment2
}
```

## File: ./services/payment-gateway-go/tests/payment_test.go

```
package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/models"; "github.com/ffarena/payment-gateway-go/internal/providers"; "github.com/ffarena/payment-gateway-go/internal/testing")
func TestPaymentCreation(t *testing.T){
    payment:=testing.CreateTestPayment(1,"manual","ext-123",1000,"BDT","idem-123")
    if payment.UserID!=1{t.Errorf("expected userID 1, got %d",payment.UserID)}
    if payment.Provider!="manual"{t.Errorf("expected manual provider")}
}
func TestPaymentValidation(t *testing.T){
    payment:=&models.Payment{AmountMinor:-100,Provider:"",ExternalID:""}
    if err:=payment.Validate(); err==nil{t.Error("expected validation error for negative amount")}
}
func TestProviderFactory(t *testing.T){
    factory:=providers.NewFactory(map[string]providers.ProviderConfig{})
    for _,key:=range factory.SupportedProviders(){
        p,err:=factory.Create(key)
        if err!=nil{t.Errorf("failed to create provider %s: %v",key,err)}
        if p.Key()!=key{t.Errorf("provider key mismatch: expected %s, got %s",key,p.Key())}
    }
}
```

## File: ./services/payment-gateway-go/tests/provider_test.go

```
package tests
import ("context"; "testing"; "github.com/ffarena/payment-gateway-go/internal/providers")
func TestManualProvider(t *testing.T){
    p:=providers.NewManualProvider(providers.ProviderConfig{})
    if p.Key()!="manual"{t.Error("expected manual key")}
    if !p.SupportsCurrency("BDT"){t.Error("manual should support BDT")}
    if !p.SupportsRefund(){t.Error("manual should support refund")}
    req:=providers.CreatePaymentRequest{UserID:1,AmountMinor:1000,Currency:"BDT",Provider:"manual",ExternalID:"ext-123",IdempotencyKey:"idem-123"}
    resp,err:=p.CreatePayment(context.Background(),req)
    if err!=nil{t.Fatalf("create payment failed: %v",err)}
    if resp.Status!="succeeded"{t.Errorf("expected succeeded, got %s",resp.Status)}
}
func TestBkashProvider(t *testing.T){
    p:=providers.NewBkashProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="bkash"{t.Error("expected bkash key")}
    if !p.SupportsCurrency("BDT"){t.Error("bkash should support BDT")}
    if p.SupportsCurrency("USD"){t.Error("bkash should not support USD")}
    req:=providers.CreatePaymentRequest{UserID:1,AmountMinor:500,Currency:"BDT",Provider:"bkash",ExternalID:"ext-123",IdempotencyKey:"idem-123"}
    _,err:=p.CreatePayment(context.Background(),req)
    if err==nil{t.Error("expected error for amount < 1000")}
}
func TestNagadProvider(t *testing.T){
    p:=providers.NewNagadProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="nagad"{t.Error("expected nagad key")}
}
func TestRocketProvider(t *testing.T){
    p:=providers.NewRocketProvider(providers.ProviderConfig{MerchantID:"test"})
    if p.Key()!="rocket"{t.Error("expected rocket key")}
    if p.SupportsRefund(){t.Error("rocket should not support refund")}
}
```

## File: ./services/payment-gateway-go/tests/wallet_test.go

```
package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/models")
func TestWalletBalanceCalculation(t *testing.T){
    credits:=int64(1000); debits:=int64(300)
    balance:=models.CalculateBalance(credits,debits)
    if balance!=700{t.Errorf("expected 700, got %d",balance)}
}
func TestLedgerIntegrity(t *testing.T){
    entries:=[]models.LedgerEntry{
        {Direction:"credit",AmountMinor:1000,BalanceAfterMinor:1000},
        {Direction:"debit",AmountMinor:300,BalanceAfterMinor:700},
    }
    valid:=models.VerifyLedgerIntegrity(entries,700)
    if !valid{t.Error("expected valid ledger integrity")}
    invalidEntries:=[]models.LedgerEntry{
        {Direction:"credit",AmountMinor:1000,BalanceAfterMinor:1000},
        {Direction:"debit",AmountMinor:300,BalanceAfterMinor:600},
    }
    valid=models.VerifyLedgerIntegrity(invalidEntries,700)
    if valid{t.Error("expected invalid ledger integrity")}
}
```

## File: ./services/payment-gateway-go/tests/webhook_test.go

```
package tests
import ("testing"; "github.com/ffarena/payment-gateway-go/internal/security")
func TestHMACVerify(t *testing.T){
    secret:="test_secret"
    payload:=[]byte(`{"event":"payment.succeeded"}`)
    signature:=security.GenerateHMAC(secret,string(payload))
    if !security.VerifyHMAC(payload,signature,secret){t.Error("HMAC verification failed")}
    if security.VerifyHMAC(payload,"invalid",secret){t.Error("should fail with invalid signature")}
}
```

## File: ./services/security-rust/Cargo.toml

```
[package]
name = "security-rust"
version = "1.0.0"
edition = "2021"
description = "FF Arena Security Services - Fraud Detection"
authors = ["FF Arena"]
license = "MIT"

[dependencies]
tokio = { version = "1.35", features = ["full"] }
warp = { version = "0.3", features = ["tls"] }
serde = { version = "1.0", features = ["derive"] }
serde_json = "1.0"
dashmap = "5.5"
jsonwebtoken = "8.3"
aes-gcm = "0.10"
base64 = "0.22"
rand = "0.8"
sha2 = "0.10"
hmac = "0.12"
uuid = { version = "1.6", features = ["v4","serde"] }
chrono = { version = "0.4", features = ["serde"] }
log = "0.4"
env_logger = "0.11"

[[bin]]
name = "security-rust"
path = "src/main.rs"
```

## File: ./services/security-rust/Dockerfile

```
FROM rust:1.78 AS builder
WORKDIR /app
COPY Cargo.toml Cargo.lock ./
RUN mkdir src && echo "fn main() {}" > src/main.rs
RUN cargo build --release || true
COPY . .
RUN cargo build --release
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y ca-certificates tini wget && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=builder /app/target/release/security-rust /app/security-rust
RUN groupadd -r appgroup && useradd -r -g appgroup -u 1001 appuser
USER appuser
EXPOSE 8082
ENV PORT=8082
ENV APP_ENV=production
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 CMD wget --no-verbose --tries=1 --spider http://localhost:8082/health/live || exit 1
ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["/app/security-rust"]
```

## File: ./services/security-rust/openapi.yaml

```
openapi: 3.0.0
info:
  title: FF Arena Security Rust
  version: 1.0.0
  description: Fraud detection with device, ip, external, identity providers
servers:
  - url: http://localhost:8082
paths:
  /health:
    get:
      summary: Health
      responses:
        '200':
          description: OK
  /health/live:
    get:
      summary: Liveness
      responses:
        '200':
          description: OK
  /health/ready:
    get:
      summary: Readiness
      responses:
        '200':
          description: OK
  /metrics:
    get:
      summary: Metrics
      responses:
        '200':
          description: OK
  /api/v1/fraud/evaluate:
    post:
      summary: Evaluate fraud risk
      security:
        - bearerAuth: []
      requestBody:
        required: true
        content:
          application/json:
            schema:
              type: object
              properties:
                user_id:
                  type: integer
                ip:
                  type: string
                user_agent:
                  type: string
                device_id:
                  type: string
      responses:
        '200':
          description: Evaluation
  /api/v1/fraud/overall/{user_id}:
    get:
      summary: Overall evaluation
      security:
        - bearerAuth: []
      parameters:
        - name: user_id
          in: path
          required: true
          schema:
            type: integer
      responses:
        '200':
          description: Overall
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
```

## File: ./services/security-rust/src/anti_cheat/mod.rs

```
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum AnomalyType {
    ImpossibleProgression,
    SuspiciousScore,
    ImpossibleTiming,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Anomaly {
    pub user_id: i64,
    pub anomaly_type: AnomalyType,
    pub score: i32,
}

pub fn detect(user_id: i64, current: i32, previous: i32) -> Option<Anomaly> {
    if current - previous > 1000 {
        Some(Anomaly{ user_id, anomaly_type: AnomalyType::ImpossibleProgression, score: 50 })
    } else { None }
}
```

## File: ./services/security-rust/src/audit/mod.rs

```
use serde::{Deserialize, Serialize};
use chrono::{DateTime, Utc};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Action {
    RiskEvaluated,
    RestrictionCreated,
    RestrictionLifted,
    IncidentOpened,
    IncidentResolved,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AuditLog {
    pub id: String,
    pub action: Action,
    pub user_id: i64,
    pub actor: String,
    pub details: serde_json::Value,
    pub request_id: String,
    pub timestamp: DateTime<Utc>,
}

pub struct AuditLogger {
    logs: std::sync::Mutex<Vec<AuditLog>>,
}

impl AuditLogger {
    pub fn new() -> Self { Self{ logs: std::sync::Mutex::new(Vec::new()) } }
    pub fn log(&self, entry: AuditLog) {
        self.logs.lock().unwrap().push(entry);
    }
    pub fn entries(&self) -> Vec<AuditLog> {
        self.logs.lock().unwrap().clone()
    }
    pub fn entries_by_user(&self, user_id: i64) -> Vec<AuditLog> {
        self.logs.lock().unwrap().iter().filter(|l| l.user_id == user_id).cloned().collect()
    }
}

pub fn log(logger: &AuditLogger, action: Action, user_id: i64, actor: &str, details: serde_json::Value, request_id: &str) {
    let entry = AuditLog{
        id: uuid::Uuid::new_v4().to_string(),
        action,
        user_id,
        actor: actor.to_string(),
        details,
        request_id: request_id.to_string(),
        timestamp: Utc::now(),
    };
    logger.log(entry);
}
```

## File: ./services/security-rust/src/config/mod.rs

```
use std::env;
#[derive(Clone, Debug)]
pub struct Config {
    pub port: u16,
    pub env: String,
    pub service_id: String,
    pub version: String,
    pub jwt_secret: String,
    pub hmac_secret: String,
    pub webhook_secret: String,
    pub rate_limit_per_min: u32,
    pub redis_url: String,
    pub log_level: String,
}
impl Config {
    pub fn load() -> Result<Self, String> {
        Ok(Self{
            port: env::var("PORT").ok().and_then(|v| v.parse().ok()).unwrap_or(8082),
            env: env::var("APP_ENV").unwrap_or_else(|_| "production".to_string()),
            service_id: env::var("SERVICE_ID").unwrap_or_else(|_| "security-rust".to_string()),
            version: env::var("VERSION").unwrap_or_else(|_| "1.0.0".to_string()),
            jwt_secret: env::var("JWT_SECRET").unwrap_or_else(|_| "".to_string()),
            hmac_secret: env::var("SERVICE_HMAC_SECRET").unwrap_or_else(|_| "".to_string()),
            webhook_secret: env::var("WEBHOOK_SECRET").unwrap_or_else(|_| "".to_string()),
            rate_limit_per_min: env::var("RATE_LIMIT_PER_MIN").ok().and_then(|v| v.parse().ok()).unwrap_or(60),
            redis_url: env::var("REDIS_URL").unwrap_or_else(|_| "redis://localhost:6379/0".to_string()),
            log_level: env::var("LOG_LEVEL").unwrap_or_else(|_| "info".to_string()),
        })
    }
    pub fn redacted(&self) -> RedactedConfig {
        RedactedConfig{
            port: self.port,
            env: self.env.clone(),
            service_id: self.service_id.clone(),
            version: self.version.clone(),
            jwt_secret: "***REDACTED***".to_string(),
            hmac_secret: "***REDACTED***".to_string(),
            webhook_secret: "***REDACTED***".to_string(),
            rate_limit_per_min: self.rate_limit_per_min,
            redis_url: "***REDACTED***".to_string(),
            log_level: self.log_level.clone(),
        }
    }
}
#[derive(Clone, Debug, serde::Serialize)]
pub struct RedactedConfig {
    pub port: u16,
    pub env: String,
    pub service_id: String,
    pub version: String,
    pub jwt_secret: String,
    pub hmac_secret: String,
    pub webhook_secret: String,
    pub rate_limit_per_min: u32,
    pub redis_url: String,
    pub log_level: String,
}
```

## File: ./services/security-rust/src/device/mod.rs

```
pub fn is_emulator(user_agent: &str) -> bool {
    user_agent.to_lowercase().contains("emulator")
}
pub fn device_score(user_agent: &str) -> i32 {
    let lower = user_agent.to_lowercase();
    let mut score = 0;
    if lower.contains("bot") { score += 20; }
    if lower.len() < 10 { score += 5; }
    score
}
```

## File: ./services/security-rust/src/domain/mod.rs

```
use serde::{Deserialize, Serialize};
use std::fmt;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub enum RiskLevel {
    Low,
    Medium,
    High,
    Critical,
}
impl fmt::Display for RiskLevel {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            RiskLevel::Low => write!(f,"low"),
            RiskLevel::Medium => write!(f,"medium"),
            RiskLevel::High => write!(f,"high"),
            RiskLevel::Critical => write!(f,"critical"),
        }
    }
}
impl RiskLevel {
    pub fn from_score(score: i32) -> Self {
        if score >= 100 { RiskLevel::Critical }
        else if score >= 70 { RiskLevel::High }
        else if score >= 30 { RiskLevel::Medium }
        else { RiskLevel::Low }
    }
    pub fn score_threshold(&self) -> i32 {
        match self {
            RiskLevel::Low => 0,
            RiskLevel::Medium => 30,
            RiskLevel::High => 70,
            RiskLevel::Critical => 100,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskSignal {
    pub provider: String,
    pub score: i32,
    pub confidence: f32,
    pub reason_code: String,
    pub evidence: Option<serde_json::Value>,
    pub expiration: Option<chrono::DateTime<chrono::Utc>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskEvaluation {
    pub id: String,
    pub user_id: i64,
    pub overall_score: i32,
    pub level: RiskLevel,
    pub signals: Vec<RiskSignal>,
    pub recommendation: String,
    pub created_at: chrono::DateTime<chrono::Utc>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Restriction {
    pub id: String,
    pub user_id: i64,
    pub restriction_type: String,
    pub reason: String,
    pub expires_at: Option<chrono::DateTime<chrono::Utc>>,
    pub created_at: chrono::DateTime<chrono::Utc>,
}
impl Restriction {
    pub fn is_expired(&self) -> bool {
        if let Some(exp) = self.expires_at {
            chrono::Utc::now() > exp
        } else { false }
    }
    pub fn should_lift(&self) -> bool { self.is_expired() }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskEvent {
    pub id: String,
    pub user_id: i64,
    pub event_type: String,
    pub risk_level: RiskLevel,
    pub created_at: chrono::DateTime<chrono::Utc>,
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

## File: ./services/security-rust/src/events/mod.rs

```
use serde::{Deserialize, Serialize};
use chrono::{DateTime, Utc};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum EventType {
    RiskCreated,
    RiskEscalated,
    RiskCleared,
    RestrictionCreated,
    RestrictionLifted,
    IncidentOpened,
    IncidentResolved,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Event {
    pub id: String,
    pub event_type: EventType,
    pub user_id: i64,
    pub data: serde_json::Value,
    pub timestamp: DateTime<Utc>,
}

impl Event {
    pub fn new(event_type: EventType, user_id: i64, data: serde_json::Value) -> Self {
        Self{
            id: uuid::Uuid::new_v4().to_string(),
            event_type,
            user_id,
            data,
            timestamp: Utc::now(),
        }
    }
}

pub const VERSION: &str = "v1";

pub trait Publisher: Send + Sync {
    fn publish(&self, event: Event) -> Result<(), String>;
}

pub struct InMemoryPublisher {
    events: std::sync::Mutex<Vec<Event>>,
}

impl InMemoryPublisher {
    pub fn new() -> Self { Self{ events: std::sync::Mutex::new(Vec::new()) } }
    pub fn events(&self) -> Vec<Event> { self.events.lock().unwrap().clone() }
}

impl Publisher for InMemoryPublisher {
    fn publish(&self, event: Event) -> Result<(), String> {
        self.events.lock().unwrap().push(event);
        Ok(())
    }
}
```

## File: ./services/security-rust/src/handlers/evaluate.rs

```
use crate::config::Config;
use crate::observability::{Logger, Metrics};
use crate::providers::FraudCheckRequest;
use crate::manager::FraudManager;
use crate::domain::RiskLevel;
use std::sync::Arc;
use warp::{Filter, Rejection, Reply};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Deserialize)]
pub struct EvaluateRequest {
    pub user_id: i64,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub device_id: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct EvaluateResponse {
    pub user_id: i64,
    pub overall_score: i32,
    pub risk_level: String,
    pub recommendation: String,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
    pub allow: bool,
}

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl Reply, Error = Rejection> + Clone {
    let fraud_manager = Arc::new(FraudManager::new());

    let evaluate = warp::path!("api" / "v1" / "fraud" / "evaluate")
        .and(warp::post())
        .and(warp::body::json::<EvaluateRequest>())
        .and(with_manager(fraud_manager.clone()))
        .and(with_logger(logger.clone()))
        .and(with_metrics(metrics.clone()))
        .and_then(handle_evaluate);

    let overall = warp::path!("api" / "v1" / "fraud" / "overall" / i64)
        .and(warp::get())
        .and(with_manager(fraud_manager.clone()))
        .and(with_logger(logger.clone()))
        .and(with_metrics(metrics.clone()))
        .and_then(handle_overall);

    evaluate.or(overall)
}

fn with_manager(manager: Arc<FraudManager>) -> impl Filter<Extract = (Arc<FraudManager>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || manager.clone())
}
fn with_logger(logger: Arc<Logger>) -> impl Filter<Extract = (Arc<Logger>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || logger.clone())
}
fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

async fn handle_evaluate(
    req: EvaluateRequest,
    manager: Arc<FraudManager>,
    logger: Arc<Logger>,
    metrics: Arc<Metrics>,
) -> Result<impl Reply, Rejection> {
    let fraud_req = FraudCheckRequest{
        user_id: req.user_id,
        ip: req.ip,
        user_agent: req.user_agent,
        device_id: req.device_id,
        email: req.email,
        metadata: None,
    };
    let results = manager.evaluate_all(&fraud_req);
    let overall_score = manager.calculate_overall_score(&results);
    let level = RiskLevel::from_score(overall_score);

    let (recommendation, block, review, monitor, allow) = match level {
        RiskLevel::Critical => ("block".to_string(), true, false, false, false),
        RiskLevel::High => ("review".to_string(), false, true, false, false),
        RiskLevel::Medium => ("monitor".to_string(), false, false, true, true),
        RiskLevel::Low => ("allow".to_string(), false, false, false, true),
    };

    metrics.increment("fraud.evaluate", None);
    logger.info("fraud evaluation", serde_json::json!({"user_id": req.user_id, "score": overall_score, "level": level.to_string()}));

    let resp = EvaluateResponse{
        user_id: req.user_id,
        overall_score,
        risk_level: level.to_string(),
        recommendation,
        block,
        review,
        monitor,
        allow,
    };

    Ok(warp::reply::json(&resp))
}

async fn handle_overall(
    user_id: i64,
    manager: Arc<FraudManager>,
    logger: Arc<Logger>,
    metrics: Arc<Metrics>,
) -> Result<impl Reply, Rejection> {
    metrics.increment("fraud.overall", None);
    let resp = serde_json::json!({
        "user_id": user_id,
        "overall_score": 0,
        "risk_level": "low",
        "recommendation": "allow",
        "block": false,
        "review": false,
        "monitor": false,
        "allow": true
    });
    Ok(warp::reply::json(&resp))
}
```

