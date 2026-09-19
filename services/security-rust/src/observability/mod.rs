pub mod metrics;
pub mod logger;

pub use metrics::Metrics;
pub use logger::Logger;
use std::collections::HashMap;
use std::sync::{Arc, Mutex};

#[derive(Clone)]
pub struct HealthChecker {
    checks: Arc<Mutex<HashMap<String, Box<dyn Fn() -> HealthCheckResult + Send + Sync>>>>,
}

#[derive(Debug, Clone, serde::Serialize)]
pub struct HealthCheckResult {
    pub status: String,
    pub message: Option<String>,
    pub latency_ms: Option<u64>,
}

impl HealthChecker {
    pub fn new() -> Self {
        Self{ checks: Arc::new(Mutex::new(HashMap::new())) }
    }
    pub fn register<F>(&self, name: &str, check: F) where F: Fn() -> HealthCheckResult + Send + Sync + 'static {
        self.checks.lock().unwrap().insert(name.to_string(), Box::new(check));
    }
}

pub static METRICS: once_cell::sync::Lazy<Arc<Metrics>> = once_cell::sync::Lazy::new(|| Arc::new(Metrics::new()));

mod once_cell {
    pub mod sync {
        use std::sync::OnceLock;
        pub struct Lazy<T>(OnceLock<T>, fn() -> T);
        impl<T> Lazy<T> {
            pub const fn new(f: fn() -> T) -> Self { Self(OnceLock::new(), f) }
            pub fn force(&self) -> &T { self.0.get_or_init(self.1) }
        }
        impl<T> std::ops::Deref for Lazy<T> {
            type Target = T;
            fn deref(&self) -> &Self::Target { self.force() }
        }
    }
}
