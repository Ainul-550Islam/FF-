use std::collections::HashMap;
use std::sync::{Arc, Mutex};
use std::time::{Instant, Duration};
use warp::{Filter, Rejection, Reply};

pub struct RateLimiter {
    requests: Arc<Mutex<HashMap<String, Vec<Instant>>>>,
    limit: u32,
    window: Duration,
}

impl RateLimiter {
    pub fn new(limit: u32, window: Duration) -> Self {
        Self{
            requests: Arc::new(Mutex::new(HashMap::new())),
            limit,
            window,
        }
    }
    pub fn allow(&self, key: &str) -> bool {
        let mut map = self.requests.lock().unwrap();
        let now = Instant::now();
        let cutoff = now - self.window;
        let entry = map.entry(key.to_string()).or_insert_with(Vec::new);
        entry.retain(|&t| t > cutoff);
        if entry.len() >= self.limit as usize {
            return false;
        }
        entry.push(now);
        true
    }
    pub fn check(&self, key: &str) -> Result<(), Rejection> {
        if !self.allow(key) {
            Err(warp::reject::custom(RateLimitExceeded))
        } else {
            Ok(())
        }
    }
}

#[derive(Debug)]
pub struct RateLimitExceeded;
impl warp::reject::Reject for RateLimitExceeded {}

pub fn with_rate_limiter(limiter: Arc<RateLimiter>) -> impl Filter<Extract = (Arc<RateLimiter>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || limiter.clone())
}

pub fn rate_limit_filter(limiter: Arc<RateLimiter>) -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any()
        .and(warp::addr::remote())
        .and_then(move |addr: Option<std::net::SocketAddr>| {
            let limiter = limiter.clone();
            async move {
                let key = addr.map(|a| a.ip().to_string()).unwrap_or_else(|| "unknown".to_string());
                limiter.check(&key).map_err(|e| e)?;
                Ok::<(), Rejection>(())
            }
        })
        .untuple_one()
}
