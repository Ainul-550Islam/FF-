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
