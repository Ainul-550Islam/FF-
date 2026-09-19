use serde::{Deserialize, Serialize};
use std::fmt;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub enum RiskLevel {
    Low,
    Medium,
    High,
    Critical,
}
impl fmt::Display for RiskLevel {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            RiskLevel::Low => write!(f,"low"),
            RiskLevel::Medium => write!(f,"medium"),
            RiskLevel::High => write!(f,"high"),
            RiskLevel::Critical => write!(f,"critical"),
        }
    }
}
impl RiskLevel {
    pub fn from_score(score: i32) -> Self {
        if score >= 100 { RiskLevel::Critical }
        else if score >= 70 { RiskLevel::High }
        else if score >= 30 { RiskLevel::Medium }
        else { RiskLevel::Low }
    }
    pub fn score_threshold(&self) -> i32 {
        match self {
            RiskLevel::Low => 0,
            RiskLevel::Medium => 30,
            RiskLevel::High => 70,
            RiskLevel::Critical => 100,
        }
    }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskSignal {
    pub provider: String,
    pub score: i32,
    pub confidence: f32,
    pub reason_code: String,
    pub evidence: Option<serde_json::Value>,
    pub expiration: Option<chrono::DateTime<chrono::Utc>>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskEvaluation {
    pub id: String,
    pub user_id: i64,
    pub overall_score: i32,
    pub level: RiskLevel,
    pub signals: Vec<RiskSignal>,
    pub recommendation: String,
    pub created_at: chrono::DateTime<chrono::Utc>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Restriction {
    pub id: String,
    pub user_id: i64,
    pub restriction_type: String,
    pub reason: String,
    pub expires_at: Option<chrono::DateTime<chrono::Utc>>,
    pub created_at: chrono::DateTime<chrono::Utc>,
}
impl Restriction {
    pub fn is_expired(&self) -> bool {
        if let Some(exp) = self.expires_at {
            chrono::Utc::now() > exp
        } else { false }
    }
    pub fn should_lift(&self) -> bool { self.is_expired() }
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct RiskEvent {
    pub id: String,
    pub user_id: i64,
    pub event_type: String,
    pub risk_level: RiskLevel,
    pub created_at: chrono::DateTime<chrono::Utc>,
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
