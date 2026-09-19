use serde_json::Value;

#[derive(Clone)]
pub struct Logger {
    service: String,
    env: String,
    version: String,
}

impl Logger {
    pub fn new(service: &str, env: &str, version: &str) -> Self {
        Self{ service: service.to_string(), env: env.to_string(), version: version.to_string() }
    }
    pub fn info(&self, msg: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "info",
            "service": self.service,
            "env": self.env,
            "version": self.version,
            "message": self.redact(msg),
            "fields": self.redact_value(fields)
        });
        println!("{}", log);
    }
    pub fn error(&self, msg: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "error",
            "service": self.service,
            "env": self.env,
            "version": self.version,
            "message": self.redact(msg),
            "fields": self.redact_value(fields)
        });
        eprintln!("{}", log);
    }
    pub fn audit(&self, action: &str, fields: Value) {
        let log = serde_json::json!({
            "level": "audit",
            "service": self.service,
            "action": action,
            "fields": self.redact_value(fields)
        });
        println!("{}", log);
    }
    fn redact(&self, s: &str) -> String {
        let lower = s.to_lowercase();
        if lower.contains("password") || lower.contains("secret") || lower.contains("token") || lower.contains("jwt") {
            "***REDACTED***".to_string()
        } else {
            s.to_string()
        }
    }
    fn redact_value(&self, value: Value) -> Value {
        match value {
            Value::Object(map) => {
                let mut new_map = serde_json::Map::new();
                for (k, v) in map {
                    let lower = k.to_lowercase();
                    if lower.contains("password") || lower.contains("secret") || lower.contains("token") || lower.contains("jwt") || lower.contains("api_key") {
                        new_map.insert(k, Value::String("***REDACTED***".to_string()));
                    } else {
                        new_map.insert(k, self.redact_value(v));
                    }
                }
                Value::Object(new_map)
            }
            _ => value,
        }
    }
}
