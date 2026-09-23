use warp::{Filter, Reply, Rejection};

/// Pass-through filter that composes into security chains. Declared with
/// `Error = Rejection` (instead of the map-based `Infallible` variant) so it
/// can be `and`-combined with auth and rate-limit filters.
pub fn security_headers() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any()
        .and_then(|| async { Ok::<(), Rejection>(()) })
        .untuple_one()
}

/// Security headers applied to every reply.
/// - X-Content-Type-Options: nosniff
/// - X-Frame-Options: SAMEORIGIN
/// - X-XSS-Protection: 1; mode=block
pub fn with_security_headers(reply: impl Reply) -> impl Reply {
    warp::reply::with_header(
        warp::reply::with_header(
            warp::reply::with_header(reply, "X-Content-Type-Options", "nosniff"),
            "X-Frame-Options",
            "SAMEORIGIN",
        ),
        "X-XSS-Protection",
        "1; mode=block",
    )
}
