pub fn is_emulator(user_agent: &str) -> bool {
    user_agent.to_lowercase().contains("emulator")
}
pub fn device_score(user_agent: &str) -> i32 {
    let lower = user_agent.to_lowercase();
    let mut score = 0;
    if lower.contains("bot") { score += 20; }
    if lower.len() < 10 { score += 5; }
    score
}
