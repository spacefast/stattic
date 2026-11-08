//! `zero abilities pull [dir] --site <url> [--only vendor/*]...`: list a site's abilities over
//! `wp-abilities/v1` and write the committed lock `zero/abilities.json`. Credentials come only from
//! `ZERO_APP_PASSWORD` (`user:application-password`), never a flag that lands in shell history.

use crate::wordpress::abilities::{self, LOCK};
use serde_json::{Value, json};
use std::path::{Path, PathBuf};
use std::process::ExitCode;
use std::time::Duration;

const USAGE: &str = "usage: zero abilities pull [project-dir] --site <url> [--only vendor/*]...\n       credentials: ZERO_APP_PASSWORD=user:application-password (optional)";

pub fn cli(args: &[String]) -> ExitCode {
    match run(args) {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{e}");
            ExitCode::from(1)
        }
    }
}

fn run(args: &[String]) -> Result<(), String> {
    let mut it = args.iter();
    if it.next().map(String::as_str) != Some("pull") {
        return Err(USAGE.into());
    }
    let (mut project, mut site, mut only) = (PathBuf::from("."), None, Vec::new());
    while let Some(a) = it.next() {
        match a.as_str() {
            "--site" => site = Some(it.next().ok_or("--site needs a URL")?.clone()),
            "--only" => only.push(it.next().ok_or("--only needs a pattern such as vendor/*")?.clone()),
            other if other.starts_with("--") => return Err(format!("unknown flag {other}\n{USAGE}")),
            other => project = PathBuf::from(other),
        }
    }
    let site = site.ok_or(format!("--site is required\n{USAGE}"))?;
    let credential = std::env::var("ZERO_APP_PASSWORD").ok().filter(|s| !s.is_empty());
    let listed = list(&site, credential.as_deref())?;
    let kept: Vec<Value> = listed
        .into_iter()
        .filter(|a| only.is_empty() || only.iter().any(|p| matches(p, a["name"].as_str().unwrap_or(""))))
        .collect();
    let mut warnings = Vec::new();
    let lock = lock(&site, &now(), &kept, &mut warnings);
    let path = project.join(LOCK);
    crate::sync::write_atomic(&path, (serde_json::to_string_pretty(&lock).unwrap() + "\n").as_bytes())?;
    for w in &warnings {
        eprintln!("warning: {w}");
    }
    println!("locked {} abilities from {site} -> {}", kept.len(), LOCK);
    Ok(())
}

/// `vendor/*` matches every ability of `vendor`; anything else is an exact name.
fn matches(pattern: &str, name: &str) -> bool {
    match pattern.strip_suffix('*') {
        Some(prefix) => name.starts_with(prefix),
        None => pattern == name,
    }
}

/// The lock document: sorted by name, each ability with a hash over its canonical schemas.
fn lock(site: &str, fetched_at: &str, listed: &[Value], warnings: &mut Vec<String>) -> Value {
    let mut items: Vec<Value> = listed
        .iter()
        .map(|a| {
            let name = a["name"].as_str().unwrap_or_default();
            for (key, schema) in [
                ("input_schema", &a["input_schema"]),
                ("output_schema", &a["output_schema"]),
            ] {
                let mut found = Vec::new();
                abilities::schema_ty(schema, &mut found, "");
                warnings.extend(found.into_iter().map(|w| format!("{name} {key}{w}")));
            }
            json!({
                "name": name,
                "label": a["label"],
                "description": a["description"],
                "category": a["category"],
                "input_schema": a["input_schema"],
                "output_schema": a["output_schema"],
                "schemaHash": schema_hash(a),
            })
        })
        .collect();
    items.sort_by(|a, b| a["name"].as_str().cmp(&b["name"].as_str()));
    json!({ "version": 1, "site": site, "fetchedAt": fetched_at, "abilities": items })
}

/// SHA-256 over the canonical (key-sorted) input and output schemas.
pub(crate) fn schema_hash(ability: &Value) -> String {
    use sha2::{Digest, Sha256};
    let canonical = canonical(&json!([ability["input_schema"], ability["output_schema"]]));
    Sha256::digest(canonical.as_bytes())
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect()
}

fn canonical(v: &Value) -> String {
    match v {
        Value::Object(o) => {
            let mut keys: Vec<&String> = o.keys().collect();
            keys.sort();
            let fields: Vec<String> = keys
                .into_iter()
                .map(|k| format!("{}:{}", serde_json::to_string(k).unwrap(), canonical(&o[k])))
                .collect();
            format!("{{{}}}", fields.join(","))
        }
        Value::Array(items) => format!("[{}]", items.iter().map(canonical).collect::<Vec<_>>().join(",")),
        other => other.to_string(),
    }
}

/// Every ability the site lists to this caller, through all pages.
pub(crate) fn list(site: &str, credential: Option<&str>) -> Result<Vec<Value>, String> {
    let base = endpoint(site)?;
    let agent: ureq::Agent = ureq::Agent::config_builder()
        .timeout_global(Some(Duration::from_secs(30)))
        .http_status_as_error(false)
        .max_redirects(0)
        .build()
        .into();
    let mut out = Vec::new();
    for page in 1.. {
        let mut url = base.clone();
        url.query_pairs_mut()
            .append_pair("per_page", "100")
            .append_pair("page", &page.to_string());
        let mut request = agent.get(url.as_str());
        if let Some(c) = credential {
            request = request.header(
                "Authorization",
                format!("Basic {}", crate::sync::client::base64(c.as_bytes())),
            );
        }
        let mut response = request.call().map_err(|e| format!("{site}: {e}"))?;
        let status = response.status().as_u16();
        let body = response
            .body_mut()
            .read_to_string()
            .map_err(|e| format!("{site}: {e}"))?;
        match status {
            200 => {}
            401 | 403 => {
                return Err(format!(
                    "{site}: listing abilities needs a signed-in user; set ZERO_APP_PASSWORD=user:application-password"
                ));
            }
            404 => {
                return Err(format!(
                    "{site}: no wp-abilities/v1 API (WordPress 6.9+ with the Abilities API)"
                ));
            }
            other => return Err(format!("{site}: listing abilities answered HTTP {other}")),
        }
        let items: Vec<Value> =
            serde_json::from_str(&body).map_err(|_| format!("{site}: the abilities list is not a JSON array"))?;
        let last = items.len() < 100;
        for item in items {
            if !item["name"].as_str().is_some_and(abilities::valid_name) {
                return Err(format!("{site}: an ability has an invalid name: {}", item["name"]));
            }
            out.push(item);
        }
        if last {
            break;
        }
    }
    Ok(out)
}

/// `<site>/wp-json/wp-abilities/v1/abilities`, or the `?rest_route=` form for a site without pretty
/// permalinks (a site URL that already carries `rest_route`).
fn endpoint(site: &str) -> Result<url::Url, String> {
    let mut url = url::Url::parse(site).map_err(|e| format!("--site: {e}"))?;
    if !matches!(url.scheme(), "http" | "https") || !url.username().is_empty() || url.password().is_some() {
        return Err("--site must be an http(s) URL without credentials".into());
    }
    if url.query_pairs().any(|(k, _)| k == "rest_route") {
        url.query_pairs_mut()
            .clear()
            .append_pair("rest_route", "/wp-abilities/v1/abilities");
    } else {
        let path = url.path().trim_end_matches('/').to_string();
        url.set_path(&format!("{path}/wp-json/wp-abilities/v1/abilities"));
        url.set_query(None);
    }
    url.set_fragment(None);
    Ok(url)
}

/// The lock's abilities a site no longer has or has changed: one line each.
pub(crate) fn drift(project: &Path, listed: &[Value]) -> Result<Vec<String>, String> {
    let path = project.join(LOCK);
    let Ok(text) = std::fs::read_to_string(&path) else {
        return Ok(Vec::new());
    };
    let lock: Value = serde_json::from_str(&text).map_err(|e| format!("{LOCK}: {e}"))?;
    let mut out = Vec::new();
    for locked in lock["abilities"].as_array().into_iter().flatten() {
        let name = locked["name"].as_str().unwrap_or_default();
        match listed.iter().find(|a| a["name"] == name) {
            None => out.push(format!("{name}: missing on the site")),
            Some(a) if Some(schema_hash(a).as_str()) != locked["schemaHash"].as_str() => {
                out.push(format!("{name}: schema changed since the pull"))
            }
            Some(_) => {}
        }
    }
    Ok(out)
}

/// The current UTC time as `YYYY-MM-DDTHH:MM:SSZ`.
fn now() -> String {
    let secs = std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0) as i64;
    let (days, rem) = (secs.div_euclid(86_400), secs.rem_euclid(86_400));
    // Howard Hinnant's civil_from_days.
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z - era * 146_097;
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let d = doy - (153 * mp + 2) / 5 + 1;
    let m = if mp < 10 { mp + 3 } else { mp - 9 };
    let y = yoe + era * 400 + i64::from(m <= 2);
    format!(
        "{y:04}-{m:02}-{d:02}T{:02}:{:02}:{:02}Z",
        rem / 3600,
        rem % 3600 / 60,
        rem % 60
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_lock_is_sorted_hashed_and_warns_on_unrepresentable_schemas() {
        let listed = [
            json!({"name": "b/two", "input_schema": {"allOf": []}, "output_schema": {}}),
            json!({"name": "a/one", "input_schema": {"type": "object", "properties": {"x": {"type": "string"}}}, "output_schema": {}}),
        ];
        let mut warnings = Vec::new();
        let lock = lock("https://example.test", "2026-11-07T00:00:00Z", &listed, &mut warnings);
        assert_eq!(lock["abilities"][0]["name"], "a/one");
        assert_eq!(
            warnings,
            ["b/two input_schema/: `allOf` is not represented; typed as unknown"]
        );
        // Key order does not change the hash; content does.
        let reordered = json!({"name": "a/one", "output_schema": {}, "input_schema": {"properties": {"x": {"type": "string"}}, "type": "object"}});
        assert_eq!(schema_hash(&reordered), lock["abilities"][0]["schemaHash"]);
        assert!(matches("woocommerce/*", "woocommerce/create-order") && !matches("woocommerce/*", "jetpack/x"));
    }
}
