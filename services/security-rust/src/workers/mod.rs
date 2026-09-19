use std::sync::Arc;
use tokio::time::{sleep, Duration};
use crate::observability::{Logger, Metrics};
use crate::storage::FraudStore;

pub struct Worker {
    store: Arc<dyn FraudStore>,
    logger: Arc<Logger>,
    metrics: Arc<Metrics>,
}

impl Worker {
    pub fn new(store: Arc<dyn FraudStore>, logger: Arc<Logger>, metrics: Arc<Metrics>) -> Self {
        Self{ store, logger, metrics }
    }
    pub async fn start(&self) {
        loop {
            self.process_pending().await;
            sleep(Duration::from_secs(5)).await;
        }
    }
    async fn process_pending(&self) {
        self.metrics.increment("worker.process_pending", None);
        // Process pending evaluations
    }
}

pub async fn process_pending(store: Arc<dyn FraudStore>, logger: Arc<Logger>, metrics: Arc<Metrics>) {
    let worker = Worker::new(store, logger, metrics);
    worker.process_pending().await;
}
