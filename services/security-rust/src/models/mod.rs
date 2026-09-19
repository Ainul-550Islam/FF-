use crate::domain::{RiskLevel, RiskSignal};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EvaluateRequest {
    pub user_id: i64,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub device_id: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct EvaluateResponse {
    pub user_id: i64,
    pub overall_score: i32,
    pub risk_level: RiskLevel,
    pub recommendation: String,
    pub signals: Vec<RiskSignal>,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
    pub allow: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct OverallEvaluation {
    pub user_id: i64,
    pub overall_score: i32,
    pub level: RiskLevel,
    pub recommendation: String,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
}
