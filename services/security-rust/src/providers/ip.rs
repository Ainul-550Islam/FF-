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
