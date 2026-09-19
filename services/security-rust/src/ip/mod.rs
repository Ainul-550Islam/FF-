pub fn is_private_ip(ip: &str) -> bool {
    ip.starts_with("10.") || ip.starts_with("192.168.") || ip.starts_with("127.")
}
pub fn ip_score(ip: &str) -> i32 {
    if is_private_ip(ip) { 0 } else { 0 }
}
