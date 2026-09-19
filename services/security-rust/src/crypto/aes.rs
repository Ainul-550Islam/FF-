use aes_gcm::{
    aead::{Aead, KeyInit},
    Aes256Gcm, Nonce,
};
use base64::{engine::general_purpose::STANDARD as BASE64, Engine as _};
use rand::RngCore;
use std::collections::HashMap;
use std::sync::{Arc, RwLock};
use std::time::{Duration, Instant};

/// AES-256-GCM token encryption/decryption for memory and Redis caching
/// - Key must be exactly 32 bytes for AES-256
/// - Nonce 12 bytes random per encryption
/// - Encrypted format: base64(nonce + ciphertext + tag) - GCM tag is 16 bytes appended
/// - No plaintext storage

#[derive(Debug)]
pub enum CryptoError {
    InvalidKeyLength,
    EncryptionFailed(String),
    DecryptionFailed(String),
    InvalidFormat,
    Expired,
}

impl std::fmt::Display for CryptoError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            CryptoError::InvalidKeyLength => write!(f, "Encryption key must be exactly 32 bytes for AES-256"),
            CryptoError::EncryptionFailed(e) => write!(f, "Encryption failed: {}", e),
            CryptoError::DecryptionFailed(e) => write!(f, "Decryption failed: {}", e),
            CryptoError::InvalidFormat => write!(f, "Invalid encrypted format"),
            CryptoError::Expired => write!(f, "Token expired"),
        }
    }
}

impl std::error::Error for CryptoError {}

pub struct AesGcmCrypto {
    key: [u8; 32],
}

impl AesGcmCrypto {
    /// Create new crypto instance with 32-byte key
    /// Key must be exactly 32 bytes for AES-256
    pub fn new(key: &[u8]) -> Result<Self, CryptoError> {
        if key.len() != 32 {
            return Err(CryptoError::InvalidKeyLength);
        }
        let mut key_array = [0u8; 32];
        key_array.copy_from_slice(key);
        Ok(Self { key: key_array })
    }

    /// Create from base64 encoded key or raw string (must be 32 bytes)
    pub fn from_key_string(key_str: &str) -> Result<Self, CryptoError> {
        // Try base64 decode first
        if let Ok(decoded) = BASE64.decode(key_str) {
            if decoded.len() == 32 {
                return Self::new(&decoded);
            }
        }
        
        // Try raw bytes
        if key_str.len() == 32 {
            return Self::new(key_str.as_bytes());
        }
        
        Err(CryptoError::InvalidKeyLength)
    }

    /// Encrypt plaintext with random nonce, returns base64(nonce + ciphertext)
    pub fn encrypt(&self, plaintext: &str) -> Result<String, CryptoError> {
        let cipher = Aes256Gcm::new_from_slice(&self.key)
            .map_err(|e| CryptoError::EncryptionFailed(e.to_string()))?;

        // Generate random 12-byte nonce
        let mut nonce_bytes = [0u8; 12];
        rand::thread_rng().fill_bytes(&mut nonce_bytes);
        let nonce = Nonce::from_slice(&nonce_bytes);

        // Encrypt
        let ciphertext = cipher
            .encrypt(nonce, plaintext.as_bytes())
            .map_err(|e| CryptoError::EncryptionFailed(e.to_string()))?;

        // Combine nonce + ciphertext (ciphertext includes GCM tag)
        let mut combined = Vec::with_capacity(nonce_bytes.len() + ciphertext.len());
        combined.extend_from_slice(&nonce_bytes);
        combined.extend_from_slice(&ciphertext);

        // Base64 encode
        Ok(BASE64.encode(combined))
    }

    /// Decrypt base64(nonce + ciphertext) to plaintext
    pub fn decrypt(&self, encrypted_b64: &str) -> Result<String, CryptoError> {
        // Base64 decode
        let combined = BASE64
            .decode(encrypted_b64)
            .map_err(|_| CryptoError::InvalidFormat)?;

        if combined.len() < 12 + 16 {
            // At least nonce (12) + tag (16)
            return Err(CryptoError::InvalidFormat);
        }

        let (nonce_bytes, ciphertext) = combined.split_at(12);
        let nonce = Nonce::from_slice(nonce_bytes);

        let cipher = Aes256Gcm::new_from_slice(&self.key)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))?;

        let plaintext_bytes = cipher
            .decrypt(nonce, ciphertext)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))?;

        String::from_utf8(plaintext_bytes)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))
    }

    /// Encrypt with associated data (for additional security)
    pub fn encrypt_with_aad(&self, plaintext: &str, aad: &[u8]) -> Result<String, CryptoError> {
        use aes_gcm::aead::Payload;

        let cipher = Aes256Gcm::new_from_slice(&self.key)
            .map_err(|e| CryptoError::EncryptionFailed(e.to_string()))?;

        let mut nonce_bytes = [0u8; 12];
        rand::thread_rng().fill_bytes(&mut nonce_bytes);
        let nonce = Nonce::from_slice(&nonce_bytes);

        let payload = Payload {
            msg: plaintext.as_bytes(),
            aad,
        };

        let ciphertext = cipher
            .encrypt(nonce, payload)
            .map_err(|e| CryptoError::EncryptionFailed(e.to_string()))?;

        let mut combined = Vec::with_capacity(nonce_bytes.len() + ciphertext.len());
        combined.extend_from_slice(&nonce_bytes);
        combined.extend_from_slice(&ciphertext);

        Ok(BASE64.encode(combined))
    }

    /// Decrypt with associated data
    pub fn decrypt_with_aad(&self, encrypted_b64: &str, aad: &[u8]) -> Result<String, CryptoError> {
        use aes_gcm::aead::Payload;

        let combined = BASE64
            .decode(encrypted_b64)
            .map_err(|_| CryptoError::InvalidFormat)?;

        if combined.len() < 12 + 16 {
            return Err(CryptoError::InvalidFormat);
        }

        let (nonce_bytes, ciphertext) = combined.split_at(12);
        let nonce = Nonce::from_slice(nonce_bytes);

        let cipher = Aes256Gcm::new_from_slice(&self.key)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))?;

        let payload = Payload {
            msg: ciphertext,
            aad,
        };

        let plaintext_bytes = cipher
            .decrypt(nonce, payload)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))?;

        String::from_utf8(plaintext_bytes)
            .map_err(|e| CryptoError::DecryptionFailed(e.to_string()))
    }
}

/// Token cache with encryption for memory and Redis
/// - Encrypted storage, no plaintext
/// - TTL support
/// - Cleanup expired

#[derive(Clone)]
struct CachedToken {
    encrypted: String,
    expires_at: Instant,
}

pub struct EncryptedTokenCache {
    crypto: Arc<AesGcmCrypto>,
    tokens: Arc<RwLock<HashMap<String, CachedToken>>>,
}

impl EncryptedTokenCache {
    pub fn new(key: &[u8]) -> Result<Self, CryptoError> {
        let crypto = AesGcmCrypto::new(key)?;
        Ok(Self {
            crypto: Arc::new(crypto),
            tokens: Arc::new(RwLock::new(HashMap::new())),
        })
    }

    pub fn from_key_string(key_str: &str) -> Result<Self, CryptoError> {
        let crypto = AesGcmCrypto::from_key_string(key_str)?;
        Ok(Self {
            crypto: Arc::new(crypto),
            tokens: Arc::new(RwLock::new(HashMap::new())),
        })
    }

    /// Get token, decrypts if not expired
    pub fn get(&self, key: &str) -> Result<Option<String>, CryptoError> {
        let tokens = self.tokens.read().unwrap();
        if let Some(cached) = tokens.get(key) {
            if Instant::now() > cached.expires_at {
                return Err(CryptoError::Expired);
            }
            let decrypted = self.crypto.decrypt(&cached.encrypted)?;
            Ok(Some(decrypted))
        } else {
            Ok(None)
        }
    }

    /// Set token with encryption and TTL
    pub fn set(&self, key: String, token: String, ttl: Duration) -> Result<(), CryptoError> {
        let encrypted = self.crypto.encrypt(&token)?;
        let mut tokens = self.tokens.write().unwrap();
        tokens.insert(
            key,
            CachedToken {
                encrypted,
                expires_at: Instant::now() + ttl,
            },
        );
        Ok(())
    }

    /// Delete token
    pub fn delete(&self, key: &str) {
        let mut tokens = self.tokens.write().unwrap();
        tokens.remove(key);
    }

    /// Cleanup expired tokens
    pub fn cleanup(&self) {
        let mut tokens = self.tokens.write().unwrap();
        let now = Instant::now();
        tokens.retain(|_, v| now <= v.expires_at);
    }

    /// Start background cleanup task
    pub fn start_cleanup(self: Arc<Self>, interval: Duration) {
        tokio::spawn(async move {
            let mut ticker = tokio::time::interval(interval);
            loop {
                ticker.tick().await;
                self.cleanup();
            }
        });
    }

    /// Stats
    pub fn stats(&self) -> TokenCacheStats {
        let tokens = self.tokens.read().unwrap();
        let now = Instant::now();
        let mut active = 0;
        let mut expired = 0;
        for v in tokens.values() {
            if now <= v.expires_at {
                active += 1;
            } else {
                expired += 1;
            }
        }
        TokenCacheStats {
            total: tokens.len(),
            active,
            expired,
        }
    }
}

#[derive(Debug, Clone, serde::Serialize)]
pub struct TokenCacheStats {
    pub total: usize,
    pub active: usize,
    pub expired: usize,
}

/// Redis-backed encrypted token cache
/// L1: In-memory encrypted, L2: Redis with encrypted values

pub struct RedisEncryptedTokenCache {
    l1_cache: Arc<EncryptedTokenCache>,
    // In production, add Redis client:
    // redis_client: redis::Client,
}

impl RedisEncryptedTokenCache {
    pub fn new(key: &[u8]) -> Result<Self, CryptoError> {
        let l1_cache = Arc::new(EncryptedTokenCache::new(key)?);
        Ok(Self {
            l1_cache,
            // redis_client: redis::Client::open(redis_url).unwrap(),
        })
    }

    pub fn from_key_string(key_str: &str) -> Result<Self, CryptoError> {
        let l1_cache = Arc::new(EncryptedTokenCache::from_key_string(key_str)?);
        Ok(Self { l1_cache })
    }

    pub fn get(&self, key: &str) -> Result<Option<String>, CryptoError> {
        // Try L1 first
        match self.l1_cache.get(key) {
            Ok(Some(token)) => return Ok(Some(token)),
            Ok(None) => {},
            Err(CryptoError::Expired) => {
                self.l1_cache.delete(key);
            },
            Err(e) => return Err(e),
        }

        // Try L2 Redis - in production:
        // let mut conn = self.redis_client.get_async_connection().await
        // if let Ok(encrypted) = conn.get(key).await {
        //     let decrypted = self.l1_cache.crypto.decrypt(&encrypted)?;
        //     // Populate L1
        //     self.l1_cache.set(key.to_string(), decrypted.clone(), Duration::from_secs(300))?;
        //     return Ok(Some(decrypted));
        // }

        Ok(None)
    }

    pub fn set(&self, key: String, token: String, ttl: Duration) -> Result<(), CryptoError> {
        // Encrypt and set L1
        self.l1_cache.set(key.clone(), token.clone(), ttl)?;

        // Set L2 Redis with encrypted value - in production:
        // let encrypted = self.l1_cache.crypto.encrypt(&token)?;
        // let mut conn = self.redis_client.get_async_connection().await
        // conn.set_ex(key, encrypted, ttl.as_secs() as usize).await

        Ok(())
    }

    pub fn delete(&self, key: &str) {
        self.l1_cache.delete(key);
        // In production: redis del
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn test_encrypt_decrypt() {
        let key = b"0123456789abcdef0123456789abcdef"; // 32 bytes
        let crypto = AesGcmCrypto::new(key).unwrap();
        let plaintext = "test_token_12345";
        let encrypted = crypto.encrypt(plaintext).unwrap();
        assert_ne!(encrypted, plaintext);
        let decrypted = crypto.decrypt(&encrypted).unwrap();
        assert_eq!(decrypted, plaintext);
    }

    #[test]
    fn test_invalid_key_length() {
        let key = b"short_key";
        let result = AesGcmCrypto::new(key);
        assert!(result.is_err());
    }

    #[test]
    fn test_token_cache() {
        let key = b"0123456789abcdef0123456789abcdef";
        let cache = EncryptedTokenCache::new(key).unwrap();
        let token = "bkash_token_abc123";
        cache.set("bkash:grant".to_string(), token.to_string(), Duration::from_secs(3600)).unwrap();
        let retrieved = cache.get("bkash:grant").unwrap().unwrap();
        assert_eq!(retrieved, token);
    }

    #[test]
    fn test_token_cache_expiry() {
        let key = b"0123456789abcdef0123456789abcdef";
        let cache = EncryptedTokenCache::new(key).unwrap();
        cache.set("test".to_string(), "token".to_string(), Duration::from_millis(1)).unwrap();
        std::thread::sleep(Duration::from_millis(2));
        let result = cache.get("test");
        assert!(result.is_err()); // Expired
    }
}
