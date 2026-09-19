use sha2::{Sha256, Digest};

pub fn hash(input: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(input.as_bytes());
    format!("{:x}", hasher.finalize())
}

pub fn hash_ip(ip: &str) -> String {
    hash(ip)
}

pub fn subnet_hash(ip: &str) -> String {
    let parts: Vec<&str> = ip.split('.').collect();
    if parts.len() == 4 {
        format!("{}.{}.{}", parts[0], parts[1], parts[2])
    } else {
        ip.to_string()
    }
}

pub fn hash_device(device_id: &str) -> String {
    hash(device_id)
}
