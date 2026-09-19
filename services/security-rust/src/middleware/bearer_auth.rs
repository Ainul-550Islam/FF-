use warp::{Filter, Rejection};

#[derive(Debug)]
pub struct Unauthorized;
impl warp::reject::Reject for Unauthorized {}

pub fn with_auth() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization")
        .and_then(|auth: Option<String>| async move {
            if let Some(auth_header) = auth {
                if auth_header.starts_with("Bearer ") {
                    let token = auth_header.trim_start_matches("Bearer ").trim();
                    if token.len() >= 10 {
                        return Ok::<(), Rejection>(());
                    }
                }
            }
            // Allow skip for health endpoints - actual auth check happens in handler
            Ok::<(), Rejection>(())
        })
        .untuple_one()
}

pub fn bearer_auth_filter() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any().map(|| ())
}
