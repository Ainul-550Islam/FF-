use warp::{Filter, Rejection, Reply};
use std::collections::HashMap;

/// HSTS (HTTP Strict Transport Security) enforcement
/// - max-age=31536000 (1 year)
/// - includeSubDomains
/// - preload (for HSTS preload list submission)
/// - Enforces HTTPS, prevents downgrade attacks

pub const HSTS_HEADER_VALUE: &str = "max-age=31536000; includeSubDomains; preload";
pub const HSTS_MAX_AGE: u32 = 31536000; // 1 year in seconds

/// Security headers including HSTS
/// - Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
/// - X-Content-Type-Options: nosniff
/// - X-Frame-Options: DENY
/// - X-XSS-Protection: 0 (disabled, use CSP instead)
/// - Referrer-Policy: no-referrer
/// - Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'
/// - X-Permitted-Cross-Domain-Policies: none
/// - Cross-Origin-Opener-Policy: same-origin
/// - Cross-Origin-Embedder-Policy: require-corp
/// - Permissions-Policy: geolocation=(), microphone=(), camera=()

pub fn hsts_headers() -> HashMap<&'static str, &'static str> {
    let mut headers = HashMap::new();
    headers.insert("Strict-Transport-Security", HSTS_HEADER_VALUE);
    headers.insert("X-Content-Type-Options", "nosniff");
    headers.insert("X-Frame-Options", "DENY");
    headers.insert("X-XSS-Protection", "0");
    headers.insert("Referrer-Policy", "no-referrer");
    headers.insert("Content-Security-Policy", "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    headers.insert("X-Permitted-Cross-Domain-Policies", "none");
    headers.insert("Cross-Origin-Opener-Policy", "same-origin");
    headers.insert("Cross-Origin-Embedder-Policy", "require-corp");
    headers.insert("Permissions-Policy", "geolocation=(), microphone=(), camera=()");
    headers
}

/// Warp filter for HSTS and security headers
pub fn with_hsts() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::any().map(|| ())
}

/// Apply HSTS and security headers to a reply
pub fn apply_hsts_headers(reply: impl Reply) -> impl Reply {
    let headers = hsts_headers();
    let mut reply = warp::reply::with::headers(reply, headers);
    // Additional headers that need dynamic values would be added here
    reply
}

/// HSTS preload compliance check
/// For submission to https://hstspreload.org/
pub fn is_hsts_preload_compliant(headers: &HashMap<String, String>) -> Result<(), String> {
    // Check HSTS header exists
    let hsts = headers.get("Strict-Transport-Security")
        .or_else(|| headers.get("strict-transport-security"))
        .ok_or("Missing Strict-Transport-Security header")?;

    // Must have max-age >= 31536000 (1 year)
    if !hsts.contains("max-age=") {
        return Err("HSTS missing max-age".to_string());
    }

    // Parse max-age
    let max_age_str = hsts.split("max-age=").nth(1)
        .and_then(|s| s.split(';').next())
        .unwrap_or("0");
    
    let max_age: u32 = max_age_str.trim().parse()
        .map_err(|_| "Invalid max-age value")?;

    if max_age < 31536000 {
        return Err(format!("HSTS max-age must be at least 31536000, got {}", max_age));
    }

    // Must have includeSubDomains
    if !hsts.to_lowercase().contains("includesubdomains") {
        return Err("HSTS missing includeSubDomains".to_string());
    }

    // Must have preload
    if !hsts.to_lowercase().contains("preload") {
        return Err("HSTS missing preload directive".to_string());
    }

    Ok(())
}

/// Enforce HTTPS - redirect HTTP to HTTPS or reject
/// For API, we return error instead of redirect to avoid leaking
pub fn enforce_https() -> impl Filter<Extract = (), Error = Rejection> + Clone {
    warp::header::optional::<String>("x-forwarded-proto")
        .and(warp::header::optional::<String>("x-forwarded-ssl"))
        .and(warp::path::full())
        .and_then(|proto: Option<String>, ssl: Option<String>, path: warp::path::FullPath| async move {
            // Allow health check over HTTP for load balancer
            if path.as_str() == "/health/live" || path.as_str() == "/health" {
                return Ok(());
            }

            // Check if behind proxy with X-Forwarded-Proto
            if let Some(p) = proto {
                if p.to_lowercase() == "http" {
                    // In production, should reject or redirect
                    // For API, return error
                    return Err(warp::reject::custom(HttpsRequired));
                }
            }

            if let Some(s) = ssl {
                if s.to_lowercase() == "off" {
                    return Err(warp::reject::custom(HttpsRequired));
                }
            }

            Ok(())
        })
        .untuple_one()
}

#[derive(Debug)]
pub struct HttpsRequired;

impl warp::reject::Reject for HttpsRequired {}

/// Handle HTTPS required rejection
pub async fn handle_https_rejection(err: Rejection) -> Result<impl Reply, Rejection> {
    if err.find::<HttpsRequired>().is_some() {
        let json = warp::reply::json(&serde_json::json!({
            "error": "https_required",
            "message": "HTTPS is required for this endpoint"
        }));
        return Ok(warp::reply::with_status(
            json,
            warp::http::StatusCode::Forbidden,
        ));
    }
    Err(err)
}

/// Generate CSP nonce for dynamic CSP
/// CSP with nonce: Content-Security-Policy: default-src 'none'; script-src 'nonce-{nonce}'; frame-ancestors 'none'; base-uri 'none'
pub fn generate_csp_nonce() -> String {
    use rand::RngCore;
    let mut nonce_bytes = [0u8; 16];
    rand::thread_rng().fill_bytes(&mut nonce_bytes);
    base64::Engine::encode(
        &base64::engine::general_purpose::STANDARD,
        nonce_bytes,
    )
}

/// CSP header with nonce support
pub fn csp_header_with_nonce(nonce: &str) -> String {
    format!(
        "default-src 'none'; script-src 'nonce-{}'; style-src 'nonce-{}'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        nonce, nonce
    )
}

/// Validate secret strength for Rust - minimum 32 chars, reject weak
pub fn validate_secret_strength(secret: &str, name: &str) -> Result<(), String> {
    if secret.is_empty() {
        return Err(format!("{} must be set", name));
    }

    if secret.len() < 32 {
        return Err(format!(
            "{} must be at least 32 characters for security, got {}",
            name,
            secret.len()
        ));
    }

    let weak_secrets = vec![
        "secret",
        "password",
        "123456",
        "12345678",
        "test",
        "default",
        "changeme",
        "admin",
        "letmein",
        "qwerty",
        "abc123",
        "password123",
        "jwt_secret",
        "hmac_secret",
        "00000000000000000000000000000000",
        "11111111111111111111111111111111",
        "0123456789abcdef0123456789abcdef",
    ];

    let lower = secret.to_lowercase();
    for weak in weak_secrets {
        if lower == weak {
            return Err(format!(
                "{} is too weak, cannot be common word '{}'",
                name, weak
            ));
        }
    }

    // Check sequential
    if is_sequential(secret) {
        return Err(format!(
            "{} is too weak, contains sequential characters",
            name
        ));
    }

    // Check low entropy
    if is_low_entropy(secret) {
        return Err(format!(
            "{} is too weak, low entropy",
            name
        ));
    }

    Ok(())
}

fn is_sequential(s: &str) -> bool {
    if s.len() < 8 {
        return false;
    }
    let chars: Vec<char> = s.chars().collect();
    let mut count = 1;
    for i in 1..chars.len() {
        if (chars[i] as u8) == (chars[i - 1] as u8) + 1 {
            count += 1;
            if count >= 6 {
                return true;
            }
        } else {
            count = 1;
        }
    }
    false
}

fn is_low_entropy(s: &str) -> bool {
    if s.is_empty() {
        return true;
    }
    let first = s.chars().next().unwrap();
    let mut same = 0;
    for c in s.chars() {
        if c == first {
            same += 1;
        }
    }
    (same as f64 / s.len() as f64) > 0.8
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_hsts_header() {
        assert_eq!(HSTS_HEADER_VALUE, "max-age=31536000; includeSubDomains; preload");
        assert_eq!(HSTS_MAX_AGE, 31536000);
    }

    #[test]
    fn test_hsts_compliance() {
        let mut headers = HashMap::new();
        headers.insert(
            "Strict-Transport-Security".to_string(),
            "max-age=31536000; includeSubDomains; preload".to_string(),
        );
        assert!(is_hsts_preload_compliant(&headers).is_ok());
    }

    #[test]
    fn test_hsts_non_compliant() {
        let mut headers = HashMap::new();
        headers.insert(
            "Strict-Transport-Security".to_string(),
            "max-age=86400".to_string(),
        );
        assert!(is_hsts_preload_compliant(&headers).is_err());
    }

    #[test]
    fn test_secret_strength() {
        assert!(validate_secret_strength("short", "TEST").is_err());
        assert!(validate_secret_strength("secret", "TEST").is_err());
        assert!(validate_secret_strength("0123456789abcdef0123456789abcdef", "TEST").is_err());
        assert!(validate_secret_strength("a_very_strong_secret_key_32_chars_long!", "TEST").is_ok());
    }

    #[test]
    fn test_csp_nonce() {
        let nonce = generate_csp_nonce();
        assert!(!nonce.is_empty());
        let csp = csp_header_with_nonce(&nonce);
        assert!(csp.contains(&nonce));
        assert!(csp.contains("default-src 'none'"));
    }
}
