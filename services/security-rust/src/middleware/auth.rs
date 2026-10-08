use serde::{Deserialize, Serialize};
use warp::{Filter, Rejection, Reply};

#[derive(Debug)]
pub struct Unauthorized {
    pub reason: String,
}

impl warp::reject::Reject for Unauthorized {}

#[derive(Debug)]
pub struct Forbidden {
    pub reason: String,
}

impl warp::reject::Reject for Forbidden {}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct AuthenticatedUser {
    pub user_id: i64,
    pub service_id: String,
    pub is_admin: bool,
    pub is_staff: bool,
    pub is_active: bool,
}

/// Validate Bearer token via Sanctum-like logic
/// - Token must exist and start with Bearer
/// - Token length >= 10
/// - Token must be found in storage (simulated via JWT verification)
/// - Expiration check
/// - isActive account status check
pub fn with_auth(
    jwt_secret: String,
) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization").and_then(move |auth: Option<String>| {
        let secret = jwt_secret.clone();
        async move {
            // Check Authorization header exists
            let auth_header = auth.ok_or_else(|| {
                warp::reject::custom(Unauthorized {
                    reason: "Authorization header required".to_string(),
                })
            })?;

            // Must start with Bearer
            if !auth_header.starts_with("Bearer ") {
                return Err(warp::reject::custom(Unauthorized {
                    reason: "Bearer token required".to_string(),
                }));
            }

            let token = auth_header.trim_start_matches("Bearer ").trim();

            // Token length check - minimum 10
            if token.len() < 10 {
                return Err(warp::reject::custom(Unauthorized {
                    reason: "Token too short".to_string(),
                }));
            }

            if token.len() > 500 {
                return Err(warp::reject::custom(Unauthorized {
                    reason: "Token too long".to_string(),
                }));
            }

            // Verify JWT or personal access token
            // In production, this would query DB for personal_access_tokens
            // Here we verify JWT signature and claims
            match crate::security::jwt::verify_jwt(token, &secret) {
                Ok(claims) => {
                    // Check expiration - verify_jwt already checks exp via jsonwebtoken
                    // Check if user is active - would query users table in production
                    // For now, check if user_id is valid
                    if claims.user_id <= 0 {
                        return Err(warp::reject::custom(Unauthorized {
                            reason: "Invalid user ID in token".to_string(),
                        }));
                    }

                    // Simulate isActive check - in production query users.is_active
                    // Here we assume active if user_id > 0
                    let user = AuthenticatedUser {
                        user_id: claims.user_id,
                        service_id: claims.service_id.clone(),
                        is_admin: false, // Would query users.is_admin in production
                        is_staff: false, // Would query users.is_staff
                        is_active: true, // Would query users.is_active
                    };

                    // isActive check
                    if !user.is_active {
                        return Err(warp::reject::custom(Forbidden {
                            reason: "Account is inactive".to_string(),
                        }));
                    }

                    Ok(user)
                }
                Err(e) => {
                    // Try to check if it's a service token (for Go/Rust inter-service)
                    // Service tokens are JWTs signed with service secret but different claims
                    if token.contains('.') && token.len() > 20 {
                        // Could be service token, try with same secret but different validation
                        // For now, reject with specific reason
                        return Err(warp::reject::custom(Unauthorized {
                            reason: format!("Invalid token: {}", e),
                        }));
                    }

                    Err(warp::reject::custom(Unauthorized {
                        reason: format!("Token not found or invalid: {}", e),
                    }))
                }
            }
        }
    })
}

/// Optional auth - allows missing token but validates if present
/// Used for endpoints that have different behavior for anon vs auth
pub fn with_optional_auth(
    jwt_secret: String,
) -> impl Filter<Extract = (Option<AuthenticatedUser>,), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization").and_then(move |auth: Option<String>| {
        let secret = jwt_secret.clone();
        async move {
            if let Some(auth_header) = auth {
                if auth_header.starts_with("Bearer ") {
                    let token = auth_header.trim_start_matches("Bearer ").trim();
                    if token.len() >= 10 {
                        if let Ok(claims) = crate::security::jwt::verify_jwt(token, &secret) {
                            if claims.user_id > 0 {
                                return Ok::<Option<AuthenticatedUser>, Rejection>(Some(
                                    AuthenticatedUser {
                                        user_id: claims.user_id,
                                        service_id: claims.service_id,
                                        is_admin: false,
                                        is_staff: false,
                                        is_active: true,
                                    },
                                ));
                            }
                        }
                    }
                }
            }
            Ok::<Option<AuthenticatedUser>, Rejection>(None)
        }
    })
}

/// Admin check - requires authenticated user with is_admin=true
pub fn with_admin_auth(
    jwt_secret: String,
) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
    with_auth(jwt_secret).and_then(|user: AuthenticatedUser| async move {
        if !user.is_admin {
            return Err(warp::reject::custom(Forbidden {
                reason: "Admin required".to_string(),
            }));
        }
        Ok(user)
    })
}

/// Staff check - requires is_staff or is_admin
pub fn with_staff_auth(
    jwt_secret: String,
) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
    with_auth(jwt_secret).and_then(|user: AuthenticatedUser| async move {
        if !user.is_staff && !user.is_admin {
            return Err(warp::reject::custom(Forbidden {
                reason: "Staff required".to_string(),
            }));
        }
        Ok(user)
    })
}

/// Strict HMAC service auth for inter-service calls (R9).
///
/// Verifies the Laravel service signature over method + full path + raw body
/// + auth headers. Pure function (no warp types) so unit tests need no
/// filter harness. `now` is unix seconds.
///
/// Header contract (mirrors ServiceAuthenticator::generateHeaders):
/// X-Service-ID (required present, informational — the single shared secret
/// authenticates, not the id), X-Timestamp (unix seconds, +-300s), X-Nonce
/// (required present; replay tracking is future work — the timestamp window
/// bounds replays), X-Signature (hex HMAC-SHA256 over
/// "METHOD:path:body:timestamp:nonce", path with a leading slash and no
/// query string, raw body bytes).
///
/// An empty secret disables verification (dev-open). Production boot refuses
/// empty secrets (Config::load -> validate_secret_strength), so that branch
/// is unreachable in production.
pub fn verify_service_signature(
    secret: &str,
    method: &str,
    path: &str,
    body: &[u8],
    service_id: &str,
    timestamp: &str,
    nonce: &str,
    signature: &str,
    now: i64,
) -> Result<(), String> {
    if secret.is_empty() {
        return Ok(());
    }
    if service_id.is_empty() || timestamp.is_empty() || nonce.is_empty() || signature.is_empty() {
        return Err("Missing service auth headers".to_string());
    }
    let ts: i64 = timestamp
        .parse()
        .map_err(|_| "Invalid timestamp".to_string())?;
    if (now - ts).abs() > 300 {
        return Err("Timestamp out of tolerance".to_string());
    }
    // The timestamp is re-encoded from the parsed int so this matches
    // sprintf('%d') exactly (no leading-zero variants).
    let body_str = String::from_utf8_lossy(body);
    let message = format!("{}:{}:{}:{}:{}", method, path, body_str, ts, nonce);
    if crate::security::hmac::verify_hmac_hex(secret, &message, signature) {
        Ok(())
    } else {
        Err("Invalid service signature".to_string())
    }
}

/// Warp filter: verifies the service signature over method + full path +
/// raw body + auth headers, and yields the raw body for the handler to
/// parse. Missing or bad credentials reject with `Unauthorized`, which maps
/// to 401 via `handle_auth_rejection` (wired with `.recover()` on the
/// evaluate routes).
///
/// The extract is a 1-tuple `(Vec<u8>,)` — warp requires every filter
/// extract to implement `Tuple`, so a bare `Vec<u8>` cannot be yielded
/// (fixed 2026-10-07 after the compiler rejected `.untuple_one()`).
pub fn with_service_auth(
    hmac_secret: String,
) -> impl Filter<Extract = (Vec<u8>,), Error = Rejection> + Clone {
    warp::method()
        .and(warp::path::full())
        .and(warp::header::optional::<String>("x-service-id"))
        .and(warp::header::optional::<String>("x-timestamp"))
        .and(warp::header::optional::<String>("x-nonce"))
        .and(warp::header::optional::<String>("x-signature"))
        .and(warp::body::bytes().map(|body: warp::hyper::body::Bytes| body.to_vec()))
        .and_then(
            move |method: warp::http::Method,
                  full_path: warp::path::FullPath,
                  service_id: Option<String>,
                  timestamp: Option<String>,
                  nonce: Option<String>,
                  signature: Option<String>,
                  body: Vec<u8>| {
                let secret = hmac_secret.clone();
                async move {
                    let now = chrono::Utc::now().timestamp();
                    verify_service_signature(
                        &secret,
                        method.as_str(),
                        full_path.as_str(),
                        &body,
                        service_id.as_deref().unwrap_or(""),
                        timestamp.as_deref().unwrap_or(""),
                        nonce.as_deref().unwrap_or(""),
                        signature.as_deref().unwrap_or(""),
                        now,
                    )
                    .map_err(|reason| warp::reject::custom(Unauthorized { reason }))?;
                    Ok::<Vec<u8>, Rejection>(body)
                }
            },
        )
}

/// Handle auth rejections with proper JSON responses and redaction
pub async fn handle_auth_rejection(err: Rejection) -> Result<impl Reply, Rejection> {
    if let Some(unauth) = err.find::<Unauthorized>() {
        let json = warp::reply::json(&serde_json::json!({
            "error": "unauthorized",
            "message": unauth.reason,
        }));
        return Ok(warp::reply::with_status(
            json,
            warp::http::StatusCode::UNAUTHORIZED,
        ));
    }

    if let Some(forbidden) = err.find::<Forbidden>() {
        let json = warp::reply::json(&serde_json::json!({
            "error": "forbidden",
            "message": forbidden.reason,
        }));
        return Ok(warp::reply::with_status(
            json,
            warp::http::StatusCode::FORBIDDEN,
        ));
    }

    Err(err)
}

/// Validate secret strength - minimum 32 chars, reject weak
pub fn validate_secret_strength(secret: &str, name: &str) -> Result<(), String> {
    if secret.is_empty() {
        return Err(format!("{} must be set", name));
    }

    if secret.len() < 32 {
        return Err(format!(
            "{} must be at least 32 characters, got {}",
            name,
            secret.len()
        ));
    }

    let weak = vec![
        "secret", "password", "123456", "test", "default", "changeme", "admin", "letmein", "qwerty",
    ];

    let lower = secret.to_lowercase();
    for w in weak {
        if lower == w {
            return Err(format!("{} is too weak, cannot be '{}'", name, w));
        }
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::verify_service_signature;
    use crate::security::hmac::generate_hmac_hex;

    const SECRET: &str = "service-auth-test-secret-0123456789ab";
    const METHOD: &str = "POST";
    const PATH: &str = "/api/v1/fraud/evaluate";
    const BODY: &[u8] = b"{\"user_id\":7}";
    const SERVICE_ID: &str = "ffarena-laravel";
    const NONCE: &str = "8f3a2c1d-0000-4000-8000-000000000000";
    const NOW: i64 = 1_789_000_000;

    fn sign(secret: &str, method: &str, path: &str, body: &[u8], ts: i64, nonce: &str) -> String {
        let message = format!(
            "{}:{}:{}:{}:{}",
            method,
            path,
            String::from_utf8_lossy(body),
            ts,
            nonce
        );
        generate_hmac_hex(secret, &message)
    }

    fn check(
        secret: &str,
        method: &str,
        path: &str,
        body: &[u8],
        service_id: &str,
        ts: &str,
        nonce: &str,
        sig: &str,
        now: i64,
    ) -> Result<(), String> {
        verify_service_signature(secret, method, path, body, service_id, ts, nonce, sig, now)
    }

    #[test]
    fn valid_signature_passes() {
        let ts = NOW.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, NOW, NONCE);
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, NONCE, &sig, NOW).is_ok());
    }

    #[test]
    fn valid_get_with_empty_body_passes() {
        let ts = NOW.to_string();
        let sig = sign(SECRET, "GET", "/api/v1/fraud/overall/7", b"", NOW, NONCE);
        assert!(check(
            SECRET,
            "GET",
            "/api/v1/fraud/overall/7",
            b"",
            SERVICE_ID,
            &ts,
            NONCE,
            &sig,
            NOW
        )
        .is_ok());
    }

    #[test]
    fn wrong_secret_rejected() {
        let ts = NOW.to_string();
        let sig = sign("wrong-secret", METHOD, PATH, BODY, NOW, NONCE);
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, NONCE, &sig, NOW).is_err());
    }

    #[test]
    fn tampered_body_rejected() {
        let ts = NOW.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, NOW, NONCE);
        assert!(check(SECRET, METHOD, PATH, b"{\"user_id\":8}", SERVICE_ID, &ts, NONCE, &sig, NOW)
            .is_err());
    }

    #[test]
    fn wrong_path_rejected() {
        let ts = NOW.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, NOW, NONCE);
        assert!(check(SECRET, METHOD, "/api/v1/fraud/overall/7", BODY, SERVICE_ID, &ts, NONCE, &sig, NOW)
            .is_err());
    }

    #[test]
    fn stale_timestamp_rejected() {
        let old = NOW - 301;
        let ts = old.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, old, NONCE);
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, NONCE, &sig, NOW).is_err());
    }

    #[test]
    fn future_timestamp_rejected() {
        let future = NOW + 301;
        let ts = future.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, future, NONCE);
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, NONCE, &sig, NOW).is_err());
    }

    #[test]
    fn unparsable_timestamp_rejected() {
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, "not-a-number", NONCE, "sig", NOW)
            .is_err());
    }

    #[test]
    fn missing_headers_rejected() {
        let ts = NOW.to_string();
        let sig = sign(SECRET, METHOD, PATH, BODY, NOW, NONCE);
        assert!(check(SECRET, METHOD, PATH, BODY, "", &ts, NONCE, &sig, NOW).is_err());
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, "", &sig, NOW).is_err());
        assert!(check(SECRET, METHOD, PATH, BODY, SERVICE_ID, &ts, NONCE, "", NOW).is_err());
    }

    #[test]
    fn empty_secret_is_dev_open() {
        assert!(check("", METHOD, PATH, BODY, "", "", "", "", NOW).is_ok());
    }
}
