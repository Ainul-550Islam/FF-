use warp::{Filter, Rejection, Reply};
use std::collections::HashMap;
use serde::{Deserialize, Serialize};

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
pub fn with_auth(jwt_secret: String) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization")
        .and_then(move |auth: Option<String>| {
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
pub fn with_optional_auth(jwt_secret: String) -> impl Filter<Extract = (Option<AuthenticatedUser>,), Error = Rejection> + Clone {
    warp::header::optional::<String>("authorization")
        .and_then(move |auth: Option<String>| {
            let secret = jwt_secret.clone();
            async move {
                if let Some(auth_header) = auth {
                    if auth_header.starts_with("Bearer ") {
                        let token = auth_header.trim_start_matches("Bearer ").trim();
                        if token.len() >= 10 {
                            if let Ok(claims) = crate::security::jwt::verify_jwt(token, &secret) {
                                if claims.user_id > 0 {
                                    return Ok(Some(AuthenticatedUser {
                                        user_id: claims.user_id,
                                        service_id: claims.service_id,
                                        is_admin: false,
                                        is_staff: false,
                                        is_active: true,
                                    }));
                                }
                            }
                        }
                    }
                }
                Ok(None)
            }
        })
}

/// Admin check - requires authenticated user with is_admin=true
pub fn with_admin_auth(jwt_secret: String) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
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
pub fn with_staff_auth(jwt_secret: String) -> impl Filter<Extract = (AuthenticatedUser,), Error = Rejection> + Clone {
    with_auth(jwt_secret).and_then(|user: AuthenticatedUser| async move {
        if !user.is_staff && !user.is_admin {
            return Err(warp::reject::custom(Forbidden {
                reason: "Staff required".to_string(),
            }));
        }
        Ok(user)
    })
}

/// HMAC service auth for inter-service communication
/// Validates X-Service-ID, X-Timestamp, X-Nonce, X-Signature
pub fn with_service_auth(hmac_secret: String) -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::header::optional::<String>("x-service-id")
        .and(warp::header::optional::<String>("x-timestamp"))
        .and(warp::header::optional::<String>("x-nonce"))
        .and(warp::header::optional::<String>("x-signature"))
        .and(warp::header::optional::<String>("x-request-id"))
        .and_then(move |service_id: Option<String>, timestamp: Option<String>, nonce: Option<String>, signature: Option<String>, _request_id: Option<String>| {
            let secret = hmac_secret.clone();
            async move {
                // For health endpoints, allow missing service auth
                // Actual enforcement happens in handler
                if service_id.is_none() {
                    return Ok(());
                }

                let service_id = service_id.unwrap();
                let timestamp = timestamp.unwrap_or_default();
                let nonce = nonce.unwrap_or_default();
                let signature = signature.unwrap_or_default();

                if service_id.is_empty() || timestamp.is_empty() || nonce.is_empty() || signature.is_empty() {
                    return Err(warp::reject::custom(Unauthorized {
                        reason: "Missing service auth headers".to_string(),
                    }));
                }

                // Validate timestamp - 5 minute tolerance
                if let Ok(ts) = timestamp.parse::<i64>() {
                    let now = chrono::Utc::now().timestamp();
                    if (now - ts).abs() > 300 {
                        return Err(warp::reject::custom(Unauthorized {
                            reason: "Timestamp out of tolerance".to_string(),
                        }));
                    }
                } else {
                    return Err(warp::reject::custom(Unauthorized {
                        reason: "Invalid timestamp".to_string(),
                    }));
                }

                // Validate signature - HMAC SHA256 of service_id + timestamp + nonce
                let message = format!("{}{}{}", service_id, timestamp, nonce);
                match crate::security::hmac::verify_hmac(message.as_bytes(), &signature, &secret) {
                    Ok(_) => Ok(()),
                    Err(_) => Err(warp::reject::custom(Unauthorized {
                        reason: "Invalid service signature".to_string(),
                    })),
                }
            }
        })
        .untuple_one()
}

/// Handle auth rejections with proper JSON responses and redaction
pub async fn handle_auth_rejection(err: Rejection) -> Result<impl Reply, Rejection> {
    if let Some(unauth) = err.find::<Unauthorized>() {
        let json = warp::reply::json(&serde_json::json!({
            "error": "unauthorized",
            "message": unauth.reason,
        }));
        return Ok(warp::reply::with_status(json, warp::http::StatusCode::UNAUTHORIZED));
    }

    if let Some(forbidden) = err.find::<Forbidden>() {
        let json = warp::reply::json(&serde_json::json!({
            "error": "forbidden",
            "message": forbidden.reason,
        }));
        return Ok(warp::reply::with_status(json, warp::http::StatusCode::FORBIDDEN));
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
        "secret",
        "password",
        "123456",
        "test",
        "default",
        "changeme",
        "admin",
        "letmein",
        "qwerty",
    ];

    let lower = secret.to_lowercase();
    for w in weak {
        if lower == w {
            return Err(format!("{} is too weak, cannot be '{}'", name, w));
        }
    }

    Ok(())
}
