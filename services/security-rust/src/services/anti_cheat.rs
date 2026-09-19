use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum AnomalyType {
    ImpossibleProgression,
    SuspiciousScore,
    ImpossibleTiming,
    RepeatedDevicePattern,
    UnusualTransactionPattern,
    AccountCluster,
    ScoreAnomaly,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct MatchAnomaly {
    pub match_id: String,
    pub user_id: i64,
    pub anomaly_type: AnomalyType,
    pub score: i32,
    pub evidence: serde_json::Value,
}

pub fn detect_impossible_progression(user_id: i64, current_level: i32, previous_level: i32) -> Option<MatchAnomaly> {
    if current_level - previous_level > 1000 {
        Some(MatchAnomaly{
            match_id: format!("match-{}", user_id),
            user_id,
            anomaly_type: AnomalyType::ImpossibleProgression,
            score: 50,
            evidence: serde_json::json!({"jump": current_level - previous_level}),
        })
    } else {
        None
    }
}

pub fn detect_transaction_anomaly(amount: i64, avg_amount: f64) -> bool {
    if avg_amount > 0.0 {
        (amount as f64) > avg_amount * 5.0
    } else {
        false
    }
}
