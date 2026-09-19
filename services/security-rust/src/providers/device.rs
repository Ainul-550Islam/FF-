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
