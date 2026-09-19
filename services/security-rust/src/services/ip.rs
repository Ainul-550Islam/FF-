pub fn is_private_ip(ip: &str) -> bool {
    ip.starts_with("10.") || ip.starts_with("192.168.") || ip.starts_with("127.") || ip.starts_with("172.16.") || ip == "::1"
}

pub fn is_tor_exit_node(ip: &str) -> bool {
    // Placeholder - would check against real Tor exit list
    false
}

pub fn is_proxy(ip: &str) -> bool {
    false
}

pub fn ip_intelligence_score(ip: &str) -> i32 {
    if is_private_ip(ip) { 0 }
    else if is_tor_exit_node(ip) { 30 }
    else { 0 }
}
