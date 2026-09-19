use serde::{Deserialize, Serialize};
use chrono::{DateTime, Utc};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum Action {
    RiskEvaluated,
    RestrictionCreated,
    RestrictionLifted,
    IncidentOpened,
    IncidentResolved,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AuditLog {
    pub id: String,
    pub action: Action,
    pub user_id: i64,
    pub actor: String,
    pub details: serde_json::Value,
    pub request_id: String,
    pub timestamp: DateTime<Utc>,
}

pub struct AuditLogger {
    logs: std::sync::Mutex<Vec<AuditLog>>,
}

impl AuditLogger {
    pub fn new() -> Self { Self{ logs: std::sync::Mutex::new(Vec::new()) } }
    pub fn log(&self, entry: AuditLog) {
        self.logs.lock().unwrap().push(entry);
    }
    pub fn entries(&self) -> Vec<AuditLog> {
        self.logs.lock().unwrap().clone()
    }
    pub fn entries_by_user(&self, user_id: i64) -> Vec<AuditLog> {
        self.logs.lock().unwrap().iter().filter(|l| l.user_id == user_id).cloned().collect()
    }
}

pub fn log(logger: &AuditLogger, action: Action, user_id: i64, actor: &str, details: serde_json::Value, request_id: &str) {
    let entry = AuditLog{
        id: uuid::Uuid::new_v4().to_string(),
        action,
        user_id,
        actor: actor.to_string(),
        details,
        request_id: request_id.to_string(),
        timestamp: Utc::now(),
    };
    logger.log(entry);
}
