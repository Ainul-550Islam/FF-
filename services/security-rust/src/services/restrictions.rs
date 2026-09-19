use crate::domain::RiskLevel;

#[derive(Debug, Clone)]
pub struct RestrictionDecision {
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
    pub reason: String,
}

pub fn evaluate_restriction(score: i32) -> RestrictionDecision {
    let level = RiskLevel::from_score(score);
    match level {
        RiskLevel::Critical => RestrictionDecision{ block: true, review: false, monitor: false, reason: "critical risk - block".to_string() },
        RiskLevel::High => RestrictionDecision{ block: false, review: true, monitor: false, reason: "high risk - review".to_string() },
        RiskLevel::Medium => RestrictionDecision{ block: false, review: false, monitor: true, reason: "medium risk - monitor".to_string() },
        RiskLevel::Low => RestrictionDecision{ block: false, review: false, monitor: false, reason: "low risk - allow".to_string() },
    }
}

pub fn should_block(score: i32) -> bool { score >= 100 }
pub fn should_review(score: i32) -> bool { score >= 70 && score < 100 }
pub fn should_monitor(score: i32) -> bool { score >= 30 && score < 70 }
