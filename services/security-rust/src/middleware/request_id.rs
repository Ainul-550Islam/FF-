use warp::{Filter, Rejection, Reply};
use uuid::Uuid;

pub fn with_request_id() -> impl Filter<Extract = (String,), Error = Rejection> + Clone {
    warp::header::optional::<String>("x-request-id")
        .map(|opt: Option<String>| opt.unwrap_or_else(|| Uuid::new_v4().to_string()))
}

pub struct RequestId(pub String);

pub fn request_id_filter() -> impl Filter<Extract = (String,), Error = std::convert::Infallible> + Clone {
    warp::any().map(|| Uuid::new_v4().to_string())
}
