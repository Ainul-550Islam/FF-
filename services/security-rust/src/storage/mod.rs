use crate::domain::{RiskEvaluation, Restriction};
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

pub trait FraudStore: Send + Sync {
    fn save_evaluation(&self, eval: RiskEvaluation) -> Result<(), String>;
    fn get_evaluation(&self, id: &str) -> Result<Option<RiskEvaluation>, String>;
    fn list_evaluations_by_user(&self, user_id: i64) -> Result<Vec<RiskEvaluation>, String>;
    fn save_restriction(&self, restriction: Restriction) -> Result<(), String>;
    fn get_restriction(&self, id: &str) -> Result<Option<Restriction>, String>;
    fn health_check(&self) -> Result<(), String>;
}

pub struct MemoryFraudStore {
    evaluations: Arc<Mutex<HashMap<String, RiskEvaluation>>>,
    restrictions: Arc<Mutex<HashMap<String, Restriction>>>,
}

impl MemoryFraudStore {
    pub fn new() -> Self {
        Self{
            evaluations: Arc::new(Mutex::new(HashMap::new())),
            restrictions: Arc::new(Mutex::new(HashMap::new())),
        }
    }
}

impl FraudStore for MemoryFraudStore {
    fn save_evaluation(&self, eval: RiskEvaluation) -> Result<(), String> {
        self.evaluations.lock().unwrap().insert(eval.id.clone(), eval);
        Ok(())
    }
    fn get_evaluation(&self, id: &str) -> Result<Option<RiskEvaluation>, String> {
        Ok(self.evaluations.lock().unwrap().get(id).cloned())
    }
    fn list_evaluations_by_user(&self, user_id: i64) -> Result<Vec<RiskEvaluation>, String> {
        let evals = self.evaluations.lock().unwrap();
        Ok(evals.values().filter(|e| e.user_id == user_id).cloned().collect())
    }
    fn save_restriction(&self, restriction: Restriction) -> Result<(), String> {
        self.restrictions.lock().unwrap().insert(restriction.id.clone(), restriction);
        Ok(())
    }
    fn get_restriction(&self, id: &str) -> Result<Option<Restriction>, String> {
        Ok(self.restrictions.lock().unwrap().get(id).cloned())
    }
    fn health_check(&self) -> Result<(), String> { Ok(()) }
}

pub struct PostgresFraudStore {
    connection_string: String,
}

impl PostgresFraudStore {
    pub fn new(connection_string: &str) -> Self {
        Self{ connection_string: connection_string.to_string() }
    }
}

impl FraudStore for PostgresFraudStore {
    fn save_evaluation(&self, _eval: RiskEvaluation) -> Result<(), String> {
        // In production would insert into postgres
        if self.connection_string.contains("***REDACTED***") {
            return Err("invalid connection string - redacted".to_string());
        }
        Ok(())
    }
    fn get_evaluation(&self, _id: &str) -> Result<Option<RiskEvaluation>, String> { Ok(None) }
    fn list_evaluations_by_user(&self, _user_id: i64) -> Result<Vec<RiskEvaluation>, String> { Ok(Vec::new()) }
    fn save_restriction(&self, _restriction: Restriction) -> Result<(), String> { Ok(()) }
    fn get_restriction(&self, _id: &str) -> Result<Option<Restriction>, String> { Ok(None) }
    fn health_check(&self) -> Result<(), String> {
        if self.connection_string.is_empty() { return Err("empty connection string".to_string()); }
        Ok(())
    }
}
