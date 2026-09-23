// ponytail: MySQL Local Database connector and JSON extractor
use crate::config::SyncConfig;
use mysql::prelude::*;
use mysql::*;
use serde_json::{Map, Value};

pub fn get_mysql_pool(config: &SyncConfig) -> Result<Pool, String> {
    let mut builder = OptsBuilder::new();
    builder = builder
        .ip_or_hostname(Some(&config.local_db_host))
        .tcp_port(config.local_db_port)
        .user(Some(&config.local_db_user))
        .db_name(Some(&config.local_db_name));

    if !config.local_db_password.is_empty() {
        builder = builder.pass(Some(&config.local_db_password));
    }

    let opts = Opts::from(builder);
    Pool::new(opts).map_err(|e| format!("MySQL Pool creation error: {}", e))
}

pub fn test_local_connection(config: &SyncConfig) -> Result<String, String> {
    let pool = get_mysql_pool(config)?;
    let mut conn = pool.get_conn().map_err(|e| format!("Connection failed: {}", e))?;
    let version: Option<String> = conn
        .query_first("SELECT VERSION()")
        .map_err(|e| format!("Query failed: {}", e))?;
    Ok(format!("Connected successfully! MySQL Version: {}", version.unwrap_or_else(|| "Unknown".to_string())))
}

pub fn fetch_table_as_json(conn: &mut PooledConn, table_name: &str) -> Result<Vec<Value>, String> {
    let query = format!("SELECT * FROM `{}`", table_name);
    let mut result = conn.query_iter(&query).map_err(|e| format!("Failed to query table {}: {}", table_name, e))?;

    let columns: Vec<String> = result.columns().as_ref().iter().map(|col| col.name_str().to_string()).collect();

    let mut rows_json = Vec::new();

    for row_result in result.by_ref() {
        let row = row_result.map_err(|e| format!("Failed to fetch row: {}", e))?;
        let mut map = Map::new();

        for (idx, col_name) in columns.iter().enumerate() {
            let val: mysql::Value = row.get(idx).unwrap_or(mysql::Value::NULL);
            let json_val = match val {
                mysql::Value::NULL => Value::Null,
                mysql::Value::Bytes(bytes) => {
                    let s = String::from_utf8_lossy(&bytes).to_string();
                    Value::String(s)
                }
                mysql::Value::Int(i) => Value::Number(serde_json::Number::from(i)),
                mysql::Value::UInt(u) => Value::Number(serde_json::Number::from(u)),
                mysql::Value::Float(f) => serde_json::Number::from_f64(f as f64).map(Value::Number).unwrap_or(Value::Null),
                mysql::Value::Double(d) => serde_json::Number::from_f64(d).map(Value::Number).unwrap_or(Value::Null),
                mysql::Value::Date(y, m, d, hh, mm, ss, _) => {
                    if hh == 0 && mm == 0 && ss == 0 {
                        Value::String(format!("{:04}-{:02}-{:02}", y, m, d))
                    } else {
                        Value::String(format!("{:04}-{:02}-{:02} {:02}:{:02}:{:02}", y, m, d, hh, mm, ss))
                    }
                }
                mysql::Value::Time(neg, d, h, m, s, _) => {
                    let prefix = if neg { "-" } else { "" };
                    Value::String(format!("{}{:02}:{:02}:{:02}", prefix, d * 24 + (h as u32), m, s))
                }
            };
            map.insert(col_name.clone(), json_val);
        }

        rows_json.push(Value::Object(map));
    }

    Ok(rows_json)
}
