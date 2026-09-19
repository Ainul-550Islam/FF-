pub mod evaluate;
pub mod health;

use crate::config::Config;
use crate::observability::{Logger, Metrics};
use std::sync::Arc;
use warp::Filter;

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl warp::Reply, Error = warp::Rejection> + Clone {
    let health_routes = health::routes(cfg.clone(), logger.clone(), metrics.clone());
    let evaluate_routes = evaluate::routes(cfg.clone(), logger.clone(), metrics.clone());
    health_routes.or(evaluate_routes).with(warp::log::custom(|info| {
        println!("{} {} {} {}ms", info.method(), info.path(), info.status(), info.elapsed().as_millis());
    }))
}
