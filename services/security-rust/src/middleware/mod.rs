use warp::{Filter, Rejection, Reply};
use std::sync::Arc;
use crate::observability::Metrics;

pub mod request_id;
pub mod security_headers;
pub mod rate_limiter;
pub mod bearer_auth;
pub mod auth;
pub mod hsts;

pub use request_id::with_request_id;
pub use security_headers::security_headers;
pub use rate_limiter::{RateLimiter, with_rate_limiter};
pub use bearer_auth::with_auth;
pub use auth::{with_auth as with_auth_strict, with_optional_auth, with_admin_auth, with_staff_auth, with_service_auth, handle_auth_rejection, validate_secret_strength};
pub use hsts::{with_hsts, apply_hsts_headers, enforce_https, handle_https_rejection, HSTS_HEADER_VALUE};

pub fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

/// Combined security middleware chain
/// - Request ID
/// - HSTS and security headers
/// - Rate limiting
/// - Auth (optional)
pub fn security_middleware_chain(metrics: Arc<Metrics>) -> impl Filter<Extract = (), Error = Rejection> + Clone {
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
