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
