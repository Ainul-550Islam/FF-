use crate::domain::RiskLevel;
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
    (70..100).contains(&score)
}

pub fn should_monitor(score: i32) -> bool {
    (30..70).contains(&score)
}
