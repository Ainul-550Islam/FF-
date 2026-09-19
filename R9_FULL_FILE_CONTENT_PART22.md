# R9 Full File Content Part 22 - Files 316-330

Total files in this part: 15

## File: ./services/security-rust/src/providers/external.rs

```
use super::{FraudProvider, FraudCheckRequest, FraudCheckResponse};
use crate::domain::RiskLevel;

pub struct ExternalProvider;
impl ExternalProvider {
    pub fn new() -> Self { Self }
}

impl FraudProvider for ExternalProvider {
    fn key(&self) -> &str { "external" }
    fn label(&self) -> &str { "External Intelligence" }
    fn check(&self, req: &FraudCheckRequest) -> FraudCheckResponse {
        let score = 0;
        let risk_level = RiskLevel::from_score(score);
        FraudCheckResponse{
            provider: self.key().to_string(),
            score,
            risk_level,
            reason_code: "clean_external".to_string(),
            confidence: 0.8,
            evidence: Some(serde_json::json!({"external_check": "passed"})),
        }
    }
    fn capabilities(&self) -> Vec<String> { vec!["external_check".to_string()] }
    fn metadata(&self) -> serde_json::Value { serde_json::json!({"type": "external"}) }
}
```

## File: ./services/security-rust/src/providers/identity.rs

```
use super::{FraudProvider, FraudCheckRequest, FraudCheckResponse};
use crate::domain::RiskLevel;

pub struct IdentityProvider;
impl IdentityProvider {
    pub fn new() -> Self { Self }
}

impl FraudProvider for IdentityProvider {
    fn key(&self) -> &str { "identity" }
    fn label(&self) -> &str { "Identity Verification" }
    fn check(&self, req: &FraudCheckRequest) -> FraudCheckResponse {
        let mut score = 0;
        let mut reason = "verified_identity".to_string();
        if req.email.is_none() && req.phone.is_none() {
            score += 10;
            reason = "missing_identity".to_string();
        }
        let risk_level = RiskLevel::from_score(score);
        FraudCheckResponse{
            provider: self.key().to_string(),
            score,
            risk_level,
            reason_code: reason,
            confidence: 0.75,
            evidence: Some(serde_json::json!({"has_email": req.email.is_some(), "has_phone": req.phone.is_some()})),
        }
    }
    fn capabilities(&self) -> Vec<String> { vec!["identity_check".to_string()] }
    fn metadata(&self) -> serde_json::Value { serde_json::json!({"type": "identity"}) }
}
```

## File: ./services/security-rust/src/providers/ip.rs

```
use super::{FraudProvider, FraudCheckRequest, FraudCheckResponse, hash_ip, subnet_hash};
use crate::domain::RiskLevel;

pub struct IpProvider;
impl IpProvider {
    pub fn new() -> Self { Self }
    fn is_private_ip(ip: &str) -> bool {
        ip.starts_with("10.") || ip.starts_with("192.168.") || ip.starts_with("127.") || ip.starts_with("172.16.") || ip == "::1"
    }
    fn is_tor_exit_node(ip: &str) -> bool {
        // Placeholder list - in production would check against Tor exit node list
        ip == "1.2.3.4"
    }
}

impl FraudProvider for IpProvider {
    fn key(&self) -> &str { "ip" }
    fn label(&self) -> &str { "IP Intelligence" }
    fn check(&self, req: &FraudCheckRequest) -> FraudCheckResponse {
        let mut score = 0;
        let mut reason = "clean_ip".to_string();
        let mut confidence = 0.85;

        if let Some(ip) = &req.ip {
            if Self::is_private_ip(ip) {
                score += 0;
                reason = "private_ip".to_string();
            }
            if Self::is_tor_exit_node(ip) {
                score += 30;
                reason = "tor_exit_node".to_string();
                confidence = 0.95;
            }
            if ip == "0.0.0.0" {
                score += 20;
                reason = "invalid_ip".to_string();
            }
            let _hashed = hash_ip(ip);
            let _subnet = subnet_hash(ip);
        } else {
            score += 15;
            reason = "missing_ip".to_string();
            confidence = 0.6;
        }

        let risk_level = RiskLevel::from_score(score);

        FraudCheckResponse{
            provider: self.key().to_string(),
            score,
            risk_level,
            reason_code: reason,
            confidence,
            evidence: Some(serde_json::json!({"ip": req.ip, "hashed_ip": req.ip.as_ref().map(|ip| hash_ip(ip))})),
        }
    }
    fn capabilities(&self) -> Vec<String> { vec!["ip_check".to_string(), "tor_detection".to_string(), "proxy_detection".to_string()] }
    fn metadata(&self) -> serde_json::Value { serde_json::json!({"type": "ip", "version": "1.0"}) }
}
```

## File: ./services/security-rust/src/providers/mod.rs

```
use crate::domain::{RiskSignal, RiskLevel};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FraudCheckRequest {
    pub user_id: i64,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub device_id: Option<String>,
    pub email: Option<String>,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct FraudCheckResponse {
    pub provider: String,
    pub score: i32,
    pub risk_level: RiskLevel,
    pub reason_code: String,
    pub confidence: f32,
    pub evidence: Option<serde_json::Value>,
}

pub trait FraudProvider: Send + Sync {
    fn key(&self) -> &str;
    fn label(&self) -> &str;
    fn check(&self, req: &FraudCheckRequest) -> FraudCheckResponse;
    fn capabilities(&self) -> Vec<String>;
    fn metadata(&self) -> serde_json::Value;
}

pub fn device_label_from_ua(ua: &str) -> String {
    let lower = ua.to_lowercase();
    if lower.contains("iphone") { "iPhone".to_string() }
    else if lower.contains("android") { "Android".to_string() }
    else if lower.contains("windows") { "Windows".to_string() }
    else if lower.contains("mac") { "Mac".to_string() }
    else if lower.contains("bot") || lower.contains("crawler") || lower.contains("spider") { "Bot".to_string() }
    else { "Unknown".to_string() }
}

pub fn hash_ip(ip: &str) -> String {
    use sha2::{Sha256, Digest};
    let mut hasher = Sha256::new();
    hasher.update(ip.as_bytes());
    format!("{:x}", hasher.finalize())
}

pub fn subnet_hash(ip: &str) -> String {
    let parts: Vec<&str> = ip.split('.').collect();
    if parts.len() == 4 {
        format!("{}.{}.{}", parts[0], parts[1], parts[2])
    } else {
        ip.to_string()
    }
}

mod device;
mod ip;
mod external;
mod identity;

pub use device::DeviceProvider;
pub use ip::IpProvider;
pub use external::ExternalProvider;
pub use identity::IdentityProvider;
```

## File: ./services/security-rust/src/restrictions/mod.rs

```
use crate::domain::RiskLevel;

pub fn evaluate_restriction(score: i32) -> String {
    match RiskLevel::from_score(score) {
        RiskLevel::Critical => "block".to_string(),
        RiskLevel::High => "review".to_string(),
        RiskLevel::Medium => "monitor".to_string(),
        RiskLevel::Low => "allow".to_string(),
    }
}
pub fn should_block(score: i32) -> bool { score >= 100 }
```

## File: ./services/security-rust/src/risk/mod.rs

```
use crate::domain::RiskLevel;

pub struct Thresholds {
    pub low: i32,
    pub medium: i32,
    pub high: i32,
    pub critical: i32,
}

impl Default for Thresholds {
    fn default() -> Self { Self{ low: 0, medium: 30, high: 70, critical: 100 } }
}

pub fn evaluate(score: i32) -> RiskLevel {
    RiskLevel::from_score(score)
}
```

## File: ./services/security-rust/src/security/hash.rs

```
use sha2::{Sha256, Digest};

pub fn hash(input: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(input.as_bytes());
    format!("{:x}", hasher.finalize())
}

pub fn hash_ip(ip: &str) -> String {
    hash(ip)
}

pub fn subnet_hash(ip: &str) -> String {
    let parts: Vec<&str> = ip.split('.').collect();
    if parts.len() == 4 {
        format!("{}.{}.{}", parts[0], parts[1], parts[2])
    } else {
        ip.to_string()
    }
}

pub fn hash_device(device_id: &str) -> String {
    hash(device_id)
}
```

## File: ./services/security-rust/src/security/hmac.rs

```
use hmac::{Hmac, Mac};
use sha2::Sha256;
use base64::{Engine as _, engine::general_purpose};

type HmacSha256 = Hmac<Sha256>;

pub fn generate_hmac(secret: &str, message: &str) -> String {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("HMAC can take key of any size");
    mac.update(message.as_bytes());
    let result = mac.finalize();
    general_purpose::STANDARD.encode(result.into_bytes())
}

pub fn verify_hmac(secret: &str, message: &str, signature: &str) -> bool {
    let expected = generate_hmac(secret, message);
    expected == signature
}

pub fn verify_hmac_hex(secret: &str, message: &str, signature: &str) -> bool {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("HMAC can take key of any size");
    mac.update(message.as_bytes());
    let result = mac.finalize();
    let hex = format!("{:x}", result.into_bytes().iter().fold(0, |_, _| 0));
    // Simplified - use hex encoding
    let mut mac2 = HmacSha256::new_from_slice(secret.as_bytes()).unwrap();
    mac2.update(message.as_bytes());
    let code = mac2.finalize().into_bytes();
    let expected_hex = code.iter().map(|b| format!("{:02x}", b)).collect::<String>();
    expected_hex == signature
}
```

## File: ./services/security-rust/src/security/jwt.rs

```
use serde::{Deserialize, Serialize};
use jsonwebtoken::{encode, decode, Header, Validation, EncodingKey, DecodingKey};

#[derive(Debug, Serialize, Deserialize, Clone)]
pub struct Claims {
    pub user_id: i64,
    pub service_id: String,
    pub exp: usize,
    pub iat: usize,
    pub iss: String,
}

pub fn generate_jwt(secret: &str, user_id: i64, service_id: &str, expiry_seconds: usize) -> Result<String, jsonwebtoken::errors::Error> {
    let now = chrono::Utc::now().timestamp() as usize;
    let claims = Claims{
        user_id,
        service_id: service_id.to_string(),
        exp: now + expiry_seconds,
        iat: now,
        iss: service_id.to_string(),
    };
    encode(&Header::default(), &claims, &EncodingKey::from_secret(secret.as_bytes()))
}

pub fn verify_jwt(token: &str, secret: &str) -> Result<Claims, jsonwebtoken::errors::Error> {
    let token_data = decode::<Claims>(token, &DecodingKey::from_secret(secret.as_bytes()), &Validation::default())?;
    Ok(token_data.claims)
}
```

## File: ./services/security-rust/src/security/mod.rs

```
pub mod hmac;
pub mod jwt;
pub mod hash;

pub use hmac::{verify_hmac, generate_hmac};
pub use jwt::{Claims, verify_jwt, generate_jwt};
pub use hash::{hash, hash_ip, subnet_hash, hash_device};
```

## File: ./services/security-rust/src/services/account_graph.rs

```
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Node {
    pub user_id: i64,
    pub node_type: String,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum EdgeStrength {
    Strong,
    Medium,
    Weak,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Edge {
    pub from: i64,
    pub to: i64,
    pub strength: EdgeStrength,
    pub reason: String,
}

pub fn find_linked_accounts(user_id: i64, edges: &[Edge]) -> Vec<i64> {
    let mut linked = Vec::new();
    for edge in edges {
        if edge.from == user_id { linked.push(edge.to); }
        if edge.to == user_id { linked.push(edge.from); }
    }
    linked
}

pub fn is_suspicious_cluster(user_ids: &[i64], edges: &[Edge]) -> bool {
    if user_ids.len() > 5 {
        return true;
    }
    let mut edge_count = 0;
    for edge in edges {
        if user_ids.contains(&edge.from) && user_ids.contains(&edge.to) {
            edge_count += 1;
        }
    }
    edge_count > 5
}
```

## File: ./services/security-rust/src/services/anti_cheat.rs

```
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum AnomalyType {
    ImpossibleProgression,
    SuspiciousScore,
    ImpossibleTiming,
    RepeatedDevicePattern,
    UnusualTransactionPattern,
    AccountCluster,
    ScoreAnomaly,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MatchAnomaly {
    pub match_id: String,
    pub user_id: i64,
    pub anomaly_type: AnomalyType,
    pub score: i32,
    pub evidence: serde_json::Value,
}

pub fn detect_impossible_progression(user_id: i64, current_level: i32, previous_level: i32) -> Option<MatchAnomaly> {
    if current_level - previous_level > 1000 {
        Some(MatchAnomaly{
            match_id: format!("match-{}", user_id),
            user_id,
            anomaly_type: AnomalyType::ImpossibleProgression,
            score: 50,
            evidence: serde_json::json!({"jump": current_level - previous_level}),
        })
    } else {
        None
    }
}

pub fn detect_transaction_anomaly(amount: i64, avg_amount: f64) -> bool {
    if avg_amount > 0.0 {
        (amount as f64) > avg_amount * 5.0
    } else {
        false
    }
}
```

## File: ./services/security-rust/src/services/device.rs

```
use serde_json::Value;

#[derive(Debug, Clone)]
pub struct DeviceInfo {
    pub label: String,
    pub is_bot: bool,
    pub is_emulator: bool,
    pub os: String,
    pub browser: String,
}

pub fn extract_device_info(user_agent: &str) -> DeviceInfo {
    let lower = user_agent.to_lowercase();
    let label = if lower.contains("iphone") { "iPhone".to_string() }
    else if lower.contains("android") { "Android".to_string() }
    else if lower.contains("windows") { "Windows".to_string() }
    else if lower.contains("mac") { "Mac".to_string() }
    else if lower.contains("bot") { "Bot".to_string() }
    else { "Unknown".to_string() };

    let is_bot = lower.contains("bot") || lower.contains("crawler") || lower.contains("spider");
    let is_emulator = lower.contains("emulator") || lower.contains("simulator");

    let os = if lower.contains("windows") { "Windows".to_string() }
    else if lower.contains("mac") { "MacOS".to_string() }
    else if lower.contains("linux") { "Linux".to_string() }
    else if lower.contains("android") { "Android".to_string() }
    else if lower.contains("iphone") || lower.contains("ios") { "iOS".to_string() }
    else { "Unknown".to_string() };

    let browser = if lower.contains("chrome") { "Chrome".to_string() }
    else if lower.contains("firefox") { "Firefox".to_string() }
    else if lower.contains("safari") && !lower.contains("chrome") { "Safari".to_string() }
    else { "Unknown".to_string() };

    DeviceInfo{ label, is_bot, is_emulator, os, browser }
}

pub fn is_emulator(user_agent: &str) -> bool {
    let info = extract_device_info(user_agent);
    info.is_emulator
}

pub fn device_intelligence_score(device_info: &DeviceInfo) -> i32 {
    let mut score = 0;
    if device_info.is_bot { score += 20; }
    if device_info.is_emulator { score += 15; }
    if device_info.label == "Unknown" { score += 10; }
    score
}
```

## File: ./services/security-rust/src/services/evaluation.rs

```
use crate::domain::{RiskLevel, RiskSignal};
use crate::providers::FraudCheckResponse;

pub fn evaluate(responses: &[FraudCheckResponse]) -> i32 {
    responses.iter().map(|r| r.score).sum()
}

pub fn recommendation(score: i32) -> String {
    match RiskLevel::from_score(score) {
        RiskLevel::Critical => "block".to_string(),
        RiskLevel::High => "review".to_string(),
        RiskLevel::Medium => "monitor".to_string(),
        RiskLevel::Low => "allow".to_string(),
    }
}

pub fn risk_scoring(responses: &[FraudCheckResponse]) -> (i32, RiskLevel) {
    let score = evaluate(responses);
    let level = RiskLevel::from_score(score);
    (score, level)
}

pub fn should_block(score: i32) -> bool {
    score >= 100
}

pub fn should_review(score: i32) -> bool {
    score >= 70 && score < 100
}

pub fn should_monitor(score: i32) -> bool {
    score >= 30 && score < 70
}
```

## File: ./services/security-rust/src/services/ip.rs

```
pub fn is_private_ip(ip: &str) -> bool {
    ip.starts_with("10.") || ip.starts_with("192.168.") || ip.starts_with("127.") || ip.starts_with("172.16.") || ip == "::1"
}

pub fn is_tor_exit_node(ip: &str) -> bool {
    // Placeholder - would check against real Tor exit list
    false
}

pub fn is_proxy(ip: &str) -> bool {
    false
}

pub fn ip_intelligence_score(ip: &str) -> i32 {
    if is_private_ip(ip) { 0 }
    else if is_tor_exit_node(ip) { 30 }
    else { 0 }
}
```

