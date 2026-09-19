use serde::{Deserialize, Serialize};

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Node {
    pub user_id: i64,
    pub node_type: String,
    pub metadata: Option<serde_json::Value>,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub enum EdgeStrength {
    Strong,
    Medium,
    Weak,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
pub struct Edge {
    pub from: i64,
    pub to: i64,
    pub strength: EdgeStrength,
    pub reason: String,
}

pub fn find_linked_accounts(user_id: i64, edges: &[Edge]) -> Vec<i64> {
    let mut linked = Vec::new();
    for edge in edges {
        if edge.from == user_id { linked.push(edge.to); }
        if edge.to == user_id { linked.push(edge.from); }
    }
    linked
}

pub fn is_suspicious_cluster(user_ids: &[i64], edges: &[Edge]) -> bool {
    if user_ids.len() > 5 {
        return true;
    }
    let mut edge_count = 0;
    for edge in edges {
        if user_ids.contains(&edge.from) && user_ids.contains(&edge.to) {
            edge_count += 1;
        }
    }
    edge_count > 5
}
