use crate::providers::{FraudProvider, FraudCheckRequest, FraudCheckResponse, DeviceProvider, IpProvider, ExternalProvider, IdentityProvider};
use std::sync::Arc;
use dashmap::DashMap;

pub struct FraudManager {
    providers: DashMap<String, Arc<dyn FraudProvider>>,
}

impl FraudManager {
    pub fn new() -> Self {
        let mgr = Self{ providers: DashMap::new() };
        mgr.register("device", Arc::new(DeviceProvider::new()));
        mgr.register("ip", Arc::new(IpProvider::new()));
        mgr.register("external", Arc::new(ExternalProvider::new()));
        mgr.register("identity", Arc::new(IdentityProvider::new()));
        mgr
    }
    pub fn register(&self, key: &str, provider: Arc<dyn FraudProvider>) {
        self.providers.insert(key.to_string(), provider);
    }
    pub fn get(&self, key: &str) -> Option<Arc<dyn FraudProvider>> {
        self.providers.get(key).map(|p| p.clone())
    }
    pub fn evaluate_all(&self, req: &FraudCheckRequest) -> Vec<FraudCheckResponse> {
        let mut results = Vec::new();
        for provider in self.providers.iter() {
            let resp = provider.value().check(req);
            results.push(resp);
        }
        results
    }
    pub fn calculate_overall_score(&self, responses: &[FraudCheckResponse]) -> i32 {
        responses.iter().map(|r| r.score).sum()
    }
    pub fn determine_level(&self, score: i32) -> crate::domain::RiskLevel {
        crate::domain::RiskLevel::from_score(score)
    }
    pub fn list_providers(&self) -> Vec<String> {
        self.providers.iter().map(|p| p.key().to_string()).collect()
    }
}

pub fn evaluate_all(manager: &FraudManager, req: &FraudCheckRequest) -> Vec<FraudCheckResponse> {
    manager.evaluate_all(req)
}
pub fn calculate_overall_score(responses: &[FraudCheckResponse]) -> i32 {
    responses.iter().map(|r| r.score).sum()
}
pub fn determine_level(score: i32) -> crate::domain::RiskLevel {
    crate::domain::RiskLevel::from_score(score)
}
