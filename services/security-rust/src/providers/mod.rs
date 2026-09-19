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
