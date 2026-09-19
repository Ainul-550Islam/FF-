use serde_json::Value;

#[derive(Debug, Clone)]
pub struct DeviceInfo {
    pub label: String,
    pub is_bot: bool,
    pub is_emulator: bool,
    pub os: String,
    pub browser: String,
}

pub fn extract_device_info(user_agent: &str) -> DeviceInfo {
    let lower = user_agent.to_lowercase();
    let label = if lower.contains("iphone") { "iPhone".to_string() }
    else if lower.contains("android") { "Android".to_string() }
    else if lower.contains("windows") { "Windows".to_string() }
    else if lower.contains("mac") { "Mac".to_string() }
    else if lower.contains("bot") { "Bot".to_string() }
    else { "Unknown".to_string() };

    let is_bot = lower.contains("bot") || lower.contains("crawler") || lower.contains("spider");
    let is_emulator = lower.contains("emulator") || lower.contains("simulator");

    let os = if lower.contains("windows") { "Windows".to_string() }
    else if lower.contains("mac") { "MacOS".to_string() }
    else if lower.contains("linux") { "Linux".to_string() }
    else if lower.contains("android") { "Android".to_string() }
    else if lower.contains("iphone") || lower.contains("ios") { "iOS".to_string() }
    else { "Unknown".to_string() };

    let browser = if lower.contains("chrome") { "Chrome".to_string() }
    else if lower.contains("firefox") { "Firefox".to_string() }
    else if lower.contains("safari") && !lower.contains("chrome") { "Safari".to_string() }
    else { "Unknown".to_string() };

    DeviceInfo{ label, is_bot, is_emulator, os, browser }
}

pub fn is_emulator(user_agent: &str) -> bool {
    let info = extract_device_info(user_agent);
    info.is_emulator
}

pub fn device_intelligence_score(device_info: &DeviceInfo) -> i32 {
    let mut score = 0;
    if device_info.is_bot { score += 20; }
    if device_info.is_emulator { score += 15; }
    if device_info.label == "Unknown" { score += 10; }
    score
}
