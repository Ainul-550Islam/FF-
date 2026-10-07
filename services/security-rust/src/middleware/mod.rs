use crate::observability::Metrics;
use std::sync::Arc;
use warp::{Filter, Rejection};

pub mod auth;
pub mod bearer_auth;
pub mod hsts;
pub mod rate_limiter;
pub mod request_id;
pub mod security_headers;

pub use hsts::with_hsts;
pub use rate_limiter::{with_rate_limiter, RateLimiter};
pub use request_id::with_request_id;
pub use security_headers::security_headers;

pub fn with_metrics(
    metrics: Arc<Metrics>,
) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

/// Combined security middleware chain
/// - Request ID
/// - HSTS and security headers
/// - Rate limiting
/// - Auth (optional)
pub fn security_middleware_chain(
    metrics: Arc<Metrics>,
) -> impl Filter<Extract = (), Error = Rejection> + Clone {
    // Shared per-instance limiter (600 requests / minute / key) backing the
    // rate-limit layer of this chain.
    let limiter = Arc::new(RateLimiter::new(600, std::time::Duration::from_secs(60)));
    with_request_id()
        .and(with_metrics(metrics))
        .and(with_hsts())
        .and(security_headers())
        .and(with_rate_limiter(limiter))
        .map(|_request_id: String, _metrics: Arc<Metrics>, _limiter: Arc<RateLimiter>| ())
        .untuple_one()
}
