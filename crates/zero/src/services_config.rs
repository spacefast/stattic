//! Private authoring bridge to the existing WordPress services filter.
//! Nothing is added to Schema: service config belongs in server-only functions.php.

use crate::compile::php_json;
use crate::diag::{Diag, Result, Span};
use crate::eval::Evaluator;
use crate::schema::{ColType, Schema};
use crate::value::Value;
use indexmap::IndexMap;
use serde_json::{Value as Json, json};

struct At<'a, 'p> {
    value: &'a Value,
    ev: &'a Evaluator<'p>,
    span: Span,
    path: String,
}

impl<'a, 'p> At<'a, 'p> {
    fn error(&self, message: &str) -> Diag {
        Diag::new(self.span, format!("{}: {message}", self.path))
    }

    fn object(&self, allowed: Option<&[&str]>) -> Result<IndexMap<String, Value>> {
        let Value::Object(map) = self.value else {
            return Err(self.error("must be an object"));
        };
        if let Some((key, span)) = self.ev.duplicate_key(self.value, None) {
            return Err(Diag::new(span, format!("{}.{}: duplicate declaration", self.path, key)));
        }
        let map = map.borrow().clone();
        if let Some(allowed) = allowed {
            for key in map.keys() {
                if !allowed.contains(&key.as_str()) {
                    return Err(self.child(key, &map[key]).error("unknown option"));
                }
            }
        }
        Ok(map)
    }

    fn child<'b>(&'b self, key: &str, value: &'b Value) -> At<'b, 'p> {
        At {
            value,
            ev: self.ev,
            span: self.ev.key_span(self.value, key).unwrap_or(self.span),
            path: format!("{}.{}", self.path, key),
        }
    }

    fn required<'b>(&'b self, map: &'b IndexMap<String, Value>, key: &str) -> Result<At<'b, 'p>> {
        map.get(key)
            .map(|value| self.child(key, value))
            .ok_or_else(|| self.error(&format!("missing `{key}`")))
    }

    fn string(&self) -> Result<String> {
        match self.value {
            Value::Str(value) => Ok(value.to_string()),
            _ => Err(self.error("must be a string")),
        }
    }

    fn integer(&self, min: i64, max: i64) -> Result<i64> {
        match self.value {
            Value::Num(n) if n.is_finite() && n.fract() == 0.0 && *n >= min as f64 && *n <= max as f64 => Ok(*n as i64),
            _ => Err(self.error(&format!("must be an integer from {min} to {max}"))),
        }
    }

    fn env(&self) -> Result<String> {
        match self.value {
            Value::Env(name) => Ok(name.to_string()),
            _ => Err(self.error("must be env(\"NAME\"); keep the value in the WordPress runtime environment")),
        }
    }

    fn nonempty_map(&self) -> Result<IndexMap<String, Value>> {
        let map = self.object(None)?;
        if map.is_empty() {
            return Err(self.error("must contain at least one entry; omit the option to disable it"));
        }
        Ok(map)
    }
}

fn identifier(name: &str, slug: bool) -> bool {
    let mut bytes = name.bytes();
    bytes.next().is_some_and(|b| {
        if slug {
            b.is_ascii_lowercase()
        } else {
            b.is_ascii_alphabetic()
        }
    }) && (!slug || name.len() <= 80)
        && bytes
            .all(|b| b.is_ascii_alphanumeric() && (!slug || !b.is_ascii_uppercase()) || b == b'_' || slug && b == b'-')
}

fn field(at: &At<'_, '_>) -> Result<Json> {
    let map = at.object(Some(&["type", "notNull", "rules"]))?;
    let kind_at = at.required(&map, "type")?;
    let kind = kind_at.string()?;
    if !["text", "email", "integer", "boolean"].contains(&kind.as_str()) {
        return Err(kind_at.error("supported form types are text, email, integer and boolean"));
    }
    let mut out = json!({"type": kind});
    if let Some(value) = map.get("notNull") {
        let Value::Bool(required) = value else {
            return Err(at.child("notNull", value).error("must be a boolean"));
        };
        out["notNull"] = json!(required);
    }
    if let Some(value) = map.get("rules") {
        let rules_at = at.child("rules", value);
        if kind == "boolean" {
            return Err(rules_at.error("boolean fields have no min/max rules"));
        }
        let rules = rules_at.object(Some(&["min", "max"]))?;
        let mut bounds = serde_json::Map::new();
        for (key, value) in &rules {
            let min = if kind == "integer" { -9_007_199_254_740_991 } else { 0 };
            bounds.insert(
                key.clone(),
                json!(rules_at.child(key, value).integer(min, 9_007_199_254_740_991)?),
            );
        }
        if let (Some(min), Some(max)) = (bounds.get("min"), bounds.get("max"))
            && min.as_i64() > max.as_i64()
        {
            return Err(rules_at.error("min must be less than or equal to max"));
        }
        out["rules"] = Json::Object(bounds);
    }
    Ok(out)
}

fn form(at: &At<'_, '_>) -> Result<Json> {
    let map = at.object(Some(&["fields", "recipient", "sender", "limit"]))?;
    let fields_at = at.required(&map, "fields")?;
    let mut fields = serde_json::Map::new();
    for (name, value) in &fields_at.nonempty_map()? {
        let at = fields_at.child(name, value);
        if !identifier(name, false) {
            return Err(at.error("use a field identifier starting with a letter"));
        }
        fields.insert(name.clone(), field(&at)?);
    }
    let mut out = json!({
        "fields": fields,
        "recipientEnv": at.required(&map, "recipient")?.env()?,
        "senderEnv": at.required(&map, "sender")?.env()?,
    });
    if let Some(value) = map.get("limit") {
        out["limit"] = json!(at.child("limit", value).integer(1, 100)?);
    }
    Ok(out)
}

fn product(at: &At<'_, '_>) -> Result<Json> {
    let map = at.object(Some(&["name", "amount", "currency"]))?;
    let name_at = at.required(&map, "name")?;
    let name = name_at.string()?;
    if name.trim().is_empty() || name.len() > 160 {
        return Err(name_at.error("must be a nonempty name of at most 160 UTF-8 bytes"));
    }
    let currency_at = at.required(&map, "currency")?;
    let currency = currency_at.string()?;
    if currency.len() != 3 || !currency.bytes().all(|b| b.is_ascii_lowercase()) {
        return Err(currency_at.error("must be three lowercase currency letters, for example \"usd\""));
    }
    Ok(json!({"name": name, "currency": currency, "amount": at.required(&map, "amount")?.integer(50, 99_999_999)?}))
}

fn search(at: &At<'_, '_>, schema: &Schema) -> Result<Json> {
    let mut out = serde_json::Map::new();
    for (key, value) in &at.nonempty_map()? {
        let at = at.child(key, value);
        let collection = schema.by_key(key).ok_or_else(|| at.error("unknown collection key"))?;
        if !collection.drafts {
            return Err(at.error("search requires a publication-enabled collection (drafts: true)"));
        }
        let Value::Array(fields) = value else {
            return Err(at.error("must be a nonempty array of text field names"));
        };
        let mut names = Vec::new();
        for value in fields.borrow().iter() {
            let name = at.child("field", value).string()?;
            if names.contains(&name) {
                return Err(at.error(&format!("duplicate search field `{name}`")));
            }
            if !collection.columns.get(&name).is_some_and(|c| c.ty == ColType::Text) {
                return Err(at.error(&format!("`{name}` must name a text column on `{key}`")));
            }
            names.push(name);
        }
        if names.is_empty() {
            return Err(at.error("must contain at least one text field"));
        }
        out.insert(collection.table.clone(), json!(names));
    }
    Ok(Json::Object(out))
}

/// Validate the evaluated `services` option with source locations and emit its private filter.
/// Call after collection extraction, then append to the generated functions.php body.
pub fn render(config: &Value, ev: &Evaluator<'_>, schema: &Schema, span: Span) -> Result<String> {
    let root = At {
        value: config,
        ev,
        span,
        path: "defineConfig".into(),
    };
    let Value::Object(cfg) = config else {
        return Err(root.error("must be an object"));
    };
    if let Some((_, span)) = ev.duplicate_key(config, Some("services")) {
        return Err(Diag::new(span, "services: duplicate declaration"));
    }
    let cfg = cfg.borrow();
    let Some(value) = cfg.get("services") else {
        return Ok(String::new());
    };
    let at = root.child("services", value);
    let map = at.object(Some(&["forms", "search", "products", "checkoutReturnUrl"]))?;
    let mut out = json!({});
    for key in ["forms", "products"] {
        if let Some(value) = map.get(key) {
            let registry = at.child(key, value);
            let mut entries = serde_json::Map::new();
            for (name, value) in &registry.nonempty_map()? {
                let at = registry.child(name, value);
                if !identifier(name, true) {
                    return Err(at.error("use a lowercase service slug of 1–80 characters, starting with a letter"));
                }
                entries.insert(name.clone(), if key == "forms" { form(&at)? } else { product(&at)? });
            }
            out[key] = Json::Object(entries);
        }
    }
    if let Some(value) = map.get("search") {
        out["search"] = search(&at.child("search", value), schema)?;
    }
    if map.contains_key("products") != map.contains_key("checkoutReturnUrl") {
        return Err(at.error("configure products and checkoutReturnUrl together"));
    }
    if let Some(value) = map.get("checkoutReturnUrl") {
        let at = at.child("checkoutReturnUrl", value);
        let value = at.string()?;
        let url = url::Url::parse(&value).ok().filter(|url| {
            let host_valid = match url.host() {
                Some(url::Host::Domain(host)) => host.trim_end_matches('.').split('.').all(|label| {
                    !label.is_empty()
                        && label.len() <= 63
                        && !label.starts_with('-')
                        && !label.ends_with('-')
                        && label.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')
                }),
                Some(url::Host::Ipv4(_) | url::Host::Ipv6(_)) => true,
                None => false,
            };
            value.trim() == value
                && !value.bytes().any(|b| b.is_ascii_control())
                && matches!(url.scheme(), "http" | "https")
                && host_valid
                && url.username().is_empty()
                && url.password().is_none()
        });
        let Some(url) = url else {
            return Err(at.error("must be an absolute HTTP(S) URL without credentials"));
        };
        out["checkoutReturnUrl"] = json!(url.as_str());
    }
    Ok(format!(
        r#"
\add_filter('zero_services_config', static function (array $config): array {{
    $authored = {};
    foreach (['forms', 'search', 'products'] as $key) {{
        if (isset($authored[$key])) {{
            $config[$key] = \array_replace($config[$key] ?? [], $authored[$key]);
        }}
    }}
    if (isset($authored['checkoutReturnUrl'])) {{
        $config['checkoutReturnUrl'] = $authored['checkoutReturnUrl'];
    }}
    return $config;
}}, 10);
"#,
        php_json(&out)
    ))
}
