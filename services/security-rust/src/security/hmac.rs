use hmac::{Hmac, Mac};
use sha2::Sha256;
use base64::{Engine as _, engine::general_purpose};

type HmacSha256 = Hmac<Sha256>;

pub fn generate_hmac(secret: &str, message: &str) -> String {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("HMAC can take key of any size");
    mac.update(message.as_bytes());
    let result = mac.finalize();
    general_purpose::STANDARD.encode(result.into_bytes())
}

pub fn verify_hmac(secret: &str, message: &str, signature: &str) -> bool {
    let expected = generate_hmac(secret, message);
    expected == signature
}

pub fn verify_hmac_hex(secret: &str, message: &str, signature: &str) -> bool {
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).expect("HMAC can take key of any size");
    mac.update(message.as_bytes());
    let result = mac.finalize();
    let hex = format!("{:x}", result.into_bytes().iter().fold(0, |_, _| 0));
    // Simplified - use hex encoding
    let mut mac2 = HmacSha256::new_from_slice(secret.as_bytes()).unwrap();
    mac2.update(message.as_bytes());
    let code = mac2.finalize().into_bytes();
    let expected_hex = code.iter().map(|b| format!("{:02x}", b)).collect::<String>();
    expected_hex == signature
}
