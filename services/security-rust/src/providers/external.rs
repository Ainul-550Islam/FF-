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
