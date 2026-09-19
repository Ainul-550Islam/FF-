use crate::config::Config;
use crate::observability::{Logger, Metrics};
use crate::providers::FraudCheckRequest;
use crate::manager::FraudManager;
use crate::domain::RiskLevel;
use std::sync::Arc;
use warp::{Filter, Rejection, Reply};
use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Deserialize)]
pub struct EvaluateRequest {
    pub user_id: i64,
    pub ip: Option<String>,
    pub user_agent: Option<String>,
    pub device_id: Option<String>,
    pub email: Option<String>,
    pub phone: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct EvaluateResponse {
    pub user_id: i64,
    pub overall_score: i32,
    pub risk_level: String,
    pub recommendation: String,
    pub block: bool,
    pub review: bool,
    pub monitor: bool,
    pub allow: bool,
}

pub fn routes(cfg: Config, logger: Arc<Logger>, metrics: Arc<Metrics>) -> impl Filter<Extract = impl Reply, Error = Rejection> + Clone {
    let fraud_manager = Arc::new(FraudManager::new());

    let evaluate = warp::path!("api" / "v1" / "fraud" / "evaluate")
        .and(warp::post())
        .and(warp::body::json::<EvaluateRequest>())
        .and(with_manager(fraud_manager.clone()))
        .and(with_logger(logger.clone()))
        .and(with_metrics(metrics.clone()))
        .and_then(handle_evaluate);

    let overall = warp::path!("api" / "v1" / "fraud" / "overall" / i64)
        .and(warp::get())
        .and(with_manager(fraud_manager.clone()))
        .and(with_logger(logger.clone()))
        .and(with_metrics(metrics.clone()))
        .and_then(handle_overall);

    evaluate.or(overall)
}

fn with_manager(manager: Arc<FraudManager>) -> impl Filter<Extract = (Arc<FraudManager>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || manager.clone())
}
fn with_logger(logger: Arc<Logger>) -> impl Filter<Extract = (Arc<Logger>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || logger.clone())
}
fn with_metrics(metrics: Arc<Metrics>) -> impl Filter<Extract = (Arc<Metrics>,), Error = std::convert::Infallible> + Clone {
    warp::any().map(move || metrics.clone())
}

async fn handle_evaluate(
    req: EvaluateRequest,
    manager: Arc<FraudManager>,
    logger: Arc<Logger>,
    metrics: Arc<Metrics>,
) -> Result<impl Reply, Rejection> {
    let fraud_req = FraudCheckRequest{
        user_id: req.user_id,
        ip: req.ip,
        user_agent: req.user_agent,
        device_id: req.device_id,
        email: req.email,
        metadata: None,
    };
    let results = manager.evaluate_all(&fraud_req);
    let overall_score = manager.calculate_overall_score(&results);
    let level = RiskLevel::from_score(overall_score);

    let (recommendation, block, review, monitor, allow) = match level {
        RiskLevel::Critical => ("block".to_string(), true, false, false, false),
        RiskLevel::High => ("review".to_string(), false, true, false, false),
        RiskLevel::Medium => ("monitor".to_string(), false, false, true, true),
        RiskLevel::Low => ("allow".to_string(), false, false, false, true),
    };

    metrics.increment("fraud.evaluate", None);
    logger.info("fraud evaluation", serde_json::json!({"user_id": req.user_id, "score": overall_score, "level": level.to_string()}));

    let resp = EvaluateResponse{
        user_id: req.user_id,
        overall_score,
        risk_level: level.to_string(),
        recommendation,
        block,
        review,
        monitor,
        allow,
    };

    Ok(warp::reply::json(&resp))
}

async fn handle_overall(
    user_id: i64,
    manager: Arc<FraudManager>,
    logger: Arc<Logger>,
    metrics: Arc<Metrics>,
) -> Result<impl Reply, Rejection> {
    metrics.increment("fraud.overall", None);
    let resp = serde_json::json!({
        "user_id": user_id,
        "overall_score": 0,
        "risk_level": "low",
        "recommendation": "allow",
        "block": false,
        "review": false,
        "monitor": false,
        "allow": true
    });
    Ok(warp::reply::json(&resp))
}
