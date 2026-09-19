use serde::{Deserialize, Serialize};
use chrono::{DateTime, Utc};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum EventType {
    RiskCreated,
    RiskEscalated,
    RiskCleared,
    RestrictionCreated,
    RestrictionLifted,
    IncidentOpened,
    IncidentResolved,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Event {
    pub id: String,
    pub event_type: EventType,
    pub user_id: i64,
    pub data: serde_json::Value,
    pub timestamp: DateTime<Utc>,
}

impl Event {
    pub fn new(event_type: EventType, user_id: i64, data: serde_json::Value) -> Self {
        Self{
            id: uuid::Uuid::new_v4().to_string(),
            event_type,
            user_id,
            data,
            timestamp: Utc::now(),
        }
    }
}

pub const VERSION: &str = "v1";

pub trait Publisher: Send + Sync {
    fn publish(&self, event: Event) -> Result<(), String>;
}

pub struct InMemoryPublisher {
    events: std::sync::Mutex<Vec<Event>>,
}

impl InMemoryPublisher {
    pub fn new() -> Self { Self{ events: std::sync::Mutex::new(Vec::new()) } }
    pub fn events(&self) -> Vec<Event> { self.events.lock().unwrap().clone() }
}

impl Publisher for InMemoryPublisher {
    fn publish(&self, event: Event) -> Result<(), String> {
        self.events.lock().unwrap().push(event);
        Ok(())
    }
}
