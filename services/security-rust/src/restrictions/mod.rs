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
