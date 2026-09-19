use crate::domain::RiskLevel;

pub struct RiskThresholds {
    pub low: i32,
    pub medium: i32,
    pub high: i32,
    pub critical: i32,
}

impl Default for RiskThresholds {
    fn default() -> Self {
        Self{ low: 0, medium: 30, high: 70, critical: 100 }
    }
}

pub fn evaluate(score: i32, thresholds: &RiskThresholds) -> RiskLevel {
    if score >= thresholds.critical { RiskLevel::Critical }
    else if score >= thresholds.high { RiskLevel::High }
    else if score >= thresholds.medium { RiskLevel::Medium }
    else { RiskLevel::Low }
}

pub fn confidence(score: i32) -> f32 {
    if score >= 100 { 0.95 }
    else if score >= 70 { 0.85 }
    else if score >= 30 { 0.7 }
    else { 0.5 }
}
