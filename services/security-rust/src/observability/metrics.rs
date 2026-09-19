use std::collections::HashMap;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct Metrics {
    counters: Arc<Mutex<HashMap<String, i64>>>,
    gauges: Arc<Mutex<HashMap<String, f64>>>,
}

impl Metrics {
    pub fn new() -> Self {
        Self{
            counters: Arc::new(Mutex::new(HashMap::new())),
            gauges: Arc::new(Mutex::new(HashMap::new())),
        }
    }
    pub fn increment(&self, name: &str, _tags: Option<HashMap<String, String>>) {
        let mut counters = self.counters.lock().unwrap();
        *counters.entry(name.to_string()).or_insert(0) += 1;
    }
    pub fn gauge(&self, name: &str, value: f64) {
        let mut gauges = self.gauges.lock().unwrap();
        gauges.insert(name.to_string(), value);
    }
    pub fn get_counter(&self, name: &str) -> i64 {
        let counters = self.counters.lock().unwrap();
        *counters.get(name).unwrap_or(&0)
    }
    pub fn all_counters(&self) -> HashMap<String, i64> {
        self.counters.lock().unwrap().clone()
    }
}

pub struct NullMetrics;
impl NullMetrics {
    pub fn increment(&self, _name: &str, _tags: Option<HashMap<String, String>>) {}
    pub fn gauge(&self, _name: &str, _value: f64) {}
}
