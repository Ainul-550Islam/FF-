use std::env;

#[derive(Clone, Debug)]
pub struct Config {
    pub port: u16,
    pub env: String,
    pub service_id: String,
    pub version: String,
    pub jwt_secret: String,
    pub hmac_secret: String,
    pub webhook_secret: String,
    pub token_encryption_key: String,
    pub rate_limit_per_min: u32,
    pub redis_url: String,
    pub database_url: String,
    pub log_level: String,
    pub cors_allowed_origins: String,
    pub jaeger_endpoint: String,
}

impl Config {
    pub fn load() -> Result<Self, String> {
        let cfg = Self {
            port: env::var("PORT")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(8082),
            env: env::var("APP_ENV").unwrap_or_else(|_| "production".to_string()),
            service_id: env::var("SERVICE_ID").unwrap_or_else(|_| "security-rust".to_string()),
            version: env::var("VERSION").unwrap_or_else(|_| "1.0.0".to_string()),
            jwt_secret: env::var("JWT_SECRET").unwrap_or_else(|_| "".to_string()),
            hmac_secret: env::var("SERVICE_HMAC_SECRET").unwrap_or_else(|_| "".to_string()),
            webhook_secret: env::var("WEBHOOK_SECRET").unwrap_or_else(|_| "".to_string()),
            token_encryption_key: env::var("TOKEN_ENCRYPTION_KEY").unwrap_or_else(|_| "".to_string()),
            rate_limit_per_min: env::var("RATE_LIMIT_PER_MIN")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(60),
            redis_url: env::var("REDIS_URL").unwrap_or_else(|_| "redis://localhost:6379/0".to_string()),
            database_url: env::var("DATABASE_URL").unwrap_or_else(|_| "postgres://ffarena:ffarena@localhost:5432/ffarena?sslmode=disable".to_string()),
            log_level: env::var("LOG_LEVEL").unwrap_or_else(|_| "info".to_string()),
            cors_allowed_origins: env::var("CORS_ALLOWED_ORIGINS").unwrap_or_else(|_| "https://ffarena.com,https://www.ffarena.com,https://api.ffarena.com".to_string()),
            jaeger_endpoint: env::var("JAEGER_ENDPOINT").unwrap_or_else(|_| "".to_string()),
        };

        // Validate payment env and general env
        cfg.validate_env()?;
        
        Ok(cfg)
    }

    pub fn validate_env(&self) -> Result<(), String> {
        let valid_envs = vec!["development", "testing", "sandbox", "staging", "production", "local", "test"];
        if !valid_envs.contains(&self.env.as_str()) {
            return Err(format!(
                "Invalid APP_ENV {}, must be one of {:?}",
                self.env, valid_envs
            ));
        }
        Ok(())
    }

    pub fn validate_secret_strength(&self) -> Result<(), String> {
        // JWT secret must be at least 32 characters
        self.validate_single_secret(&self.jwt_secret, "JWT_SECRET")?;
        self.validate_single_secret(&self.hmac_secret, "SERVICE_HMAC_SECRET")?;
        
        // Webhook secret should also be strong if set
        if !self.webhook_secret.is_empty() {
            self.validate_single_secret(&self.webhook_secret, "WEBHOOK_SECRET")?;
        }
        
        // Token encryption key must be 32 bytes for AES-256
        if !self.token_encryption_key.is_empty() {
            if self.token_encryption_key.len() != 32 {
                return Err(format!(
                    "TOKEN_ENCRYPTION_KEY must be exactly 32 bytes for AES-256, got {}",
                    self.token_encryption_key.len()
                ));
            }
            self.validate_single_secret(&self.token_encryption_key, "TOKEN_ENCRYPTION_KEY")?;
        }

        Ok(())
    }

    fn validate_single_secret(&self, secret: &str, name: &str) -> Result<(), String> {
        if secret.is_empty() {
            if self.is_production() {
                return Err(format!("{} must be set in production", name));
            }
            return Ok(());
        }

        // Minimum 32 characters
        if secret.len() < 32 {
            return Err(format!(
                "{} must be at least 32 characters for security, got {}",
                name,
                secret.len()
            ));
        }

        // Reject weak common strings
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
        ];

        let lower = secret.to_lowercase();
        for weak in weak_secrets {
            if lower == weak {
                return Err(format!(
                    "{} is too weak, cannot be common word '{}'",
                    name, weak
                ));
            }
            // Also check if secret contains weak pattern
            if lower.contains(weak) && secret.len() < 40 {
                // If secret is short and contains weak word, reject
                if lower == weak || (lower.len() <= weak.len() + 4) {
                    return Err(format!(
                        "{} is too weak, contains common pattern '{}'",
                        name, weak
                    ));
                }
            }
        }

        // Check for sequential characters (e.g., 123456, abcdef)
        if Self::is_sequential(secret) {
            return Err(format!(
                "{} is too weak, contains sequential characters",
                name
            ));
        }

        // Check entropy - should not be all same character
        if Self::is_low_entropy(secret) {
            return Err(format!(
                "{} is too weak, low entropy (repeated characters)",
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
        let mut sequential_count = 1;
        for i in 1..chars.len() {
            if (chars[i] as u8) == (chars[i-1] as u8) + 1 {
                sequential_count += 1;
                if sequential_count >= 6 {
                    return true;
                }
            } else {
                sequential_count = 1;
            }
        }
        false
    }

    fn is_low_entropy(s: &str) -> bool {
        if s.is_empty() {
            return true;
        }
        let first = s.chars().next().unwrap();
        let mut same_count = 0;
        for c in s.chars() {
            if c == first {
                same_count += 1;
            }
        }
        // If more than 80% same character, low entropy
        (same_count as f64 / s.len() as f64) > 0.8
    }

    pub fn is_production(&self) -> bool {
        self.env == "production"
    }

    pub fn is_development(&self) -> bool {
        self.env == "development" || self.env == "local"
    }

    pub fn redacted(&self) -> RedactedConfig {
        RedactedConfig {
            port: self.port,
            env: self.env.clone(),
            service_id: self.service_id.clone(),
            version: self.version.clone(),
            jwt_secret: "***REDACTED***".to_string(),
            hmac_secret: "***REDACTED***".to_string(),
            webhook_secret: "***REDACTED***".to_string(),
            token_encryption_key: "***REDACTED***".to_string(),
            rate_limit_per_min: self.rate_limit_per_min,
            redis_url: "***REDACTED***".to_string(),
            database_url: Self::redact_url(&self.database_url),
            log_level: self.log_level.clone(),
            cors_allowed_origins: self.cors_allowed_origins.clone(),
            jaeger_endpoint: if self.jaeger_endpoint.is_empty() {
                "".to_string()
            } else {
                "***REDACTED***".to_string()
            },
        }
    }

    fn redact_url(url: &str) -> String {
        if url.is_empty() {
            return "".to_string();
        }
        // Redact password in URL like postgres://user:pass@host/db
        if let Some(at_pos) = url.find('@') {
            if let Some(colon_pos) = url[..at_pos].rfind(':') {
                if let Some(slash_slash) = url.find("://") {
                    if colon_pos > slash_slash {
                        let mut redacted = url.to_string();
                        redacted.replace_range(colon_pos + 1..at_pos, "***REDACTED***");
                        return redacted;
                    }
                }
            }
        }
        // If no password pattern, check for token param
        if url.contains("password") || url.contains("secret") || url.contains("token") {
            return "***REDACTED***".to_string();
        }
        url.to_string()
    }
}

#[derive(Clone, Debug, serde::Serialize)]
pub struct RedactedConfig {
    pub port: u16,
    pub env: String,
    pub service_id: String,
    pub version: String,
    pub jwt_secret: String,
    pub hmac_secret: String,
    pub webhook_secret: String,
    pub token_encryption_key: String,
    pub rate_limit_per_min: u32,
    pub redis_url: String,
    pub database_url: String,
    pub log_level: String,
    pub cors_allowed_origins: String,
    pub jaeger_endpoint: String,
}
