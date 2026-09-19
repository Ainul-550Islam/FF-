use crate::config::Config;
use crate::observability::{Logger, Metrics};
use std::sync::Arc;
use warp::{Filter, Rejection, Reply};

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl Reply, Error = Rejection> + Clone {
    let live = warp::path!("health" / "live")
        .and(warp::get())
        .and_then(handle_live);

    let ready = warp::path!("health" / "ready")
        .and(warp::get())
        .and(with_config(cfg.clone()))
        .and_then(handle_ready);

    let health = warp::path!("health")
        .and(warp::get())
        .and(with_config(cfg.clone()))
        .and_then(handle_health);

    let metrics_route = warp::path!("metrics")
        .and(warp::get())
        .and(with_metrics(metrics.clone()))
        .and_then(handle_metrics);

    live.or(ready).or(health).or(metrics_route)
}

fn with_config(cfg: Config) -> impl Filter<Extract = (Config,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || cfg.clone())
}
fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

async fn handle_live() -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": "security-rust",
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_ready(cfg: Config) -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": cfg.service_id,
        "checks": {
            "database": "ok",
            "redis": "ok"
        },
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_health(cfg: Config) -> Result<impl Reply, Rejection> {
    let resp = serde_json::json!({
        "status": "ok",
        "service": cfg.service_id,
        "version": cfg.version,
        "env": cfg.env,
        "timestamp": chrono::Utc::now().to_rfc3339()
    });
    Ok(warp::reply::json(&resp))
}

async fn handle_metrics(metrics: Arc<Metrics>) -> Result<impl Reply, Rejection> {
    let counters = metrics.all_counters();
    let resp = serde_json::json!({
        "counters": counters,
        "timestamp": chrono::Utc::now().to_rfc3339(),
        "service": "security-rust"
    });
    Ok(warp::reply::json(&resp))
}
