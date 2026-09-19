use warp::{Filter, Reply, Rejection};

pub fn security_headers() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any().map(|| ())
}

pub fn with_security_headers(reply: impl Reply) -> impl Reply {
    warp::reply::with::headers(reply, {
        let mut headers = std::collections::HashMap::new();
        headers.insert("X-Content-Type-Options", "nosniff");
        headers.insert("X-Frame-Options", "SAMEORIGIN");
        headers.insert("X-XSS-Protection", "1; mode=block");
        headers
    })
}
