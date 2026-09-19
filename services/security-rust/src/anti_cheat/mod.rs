use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum AnomalyType {
    ImpossibleProgression,
    SuspiciousScore,
    ImpossibleTiming,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Anomaly {
    pub user_id: i64,
    pub anomaly_type: AnomalyType,
    pub score: i32,
}

pub fn detect(user_id: i64, current: i32, previous: i32) -> Option<Anomaly> {
    if current - previous > 1000 {
        Some(Anomaly{ user_id, anomaly_type: AnomalyType::ImpossibleProgression, score: 50 })
    } else { None }
}
