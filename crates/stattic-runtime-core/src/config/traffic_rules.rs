//! The `firewall` and `cache` config keys: the requests a version blocks,
//! challenges, or serves past the cache.
//!
//! One grammar, validated once, published once, read by both config lanes. The
//! rules pass through verbatim — the provider compiler that turns them into
//! edge rules lives in TypeScript, so normalizing here would hand the compiler
//! something the author never wrote.

use regex::Regex;
use serde_json::{json, Map, Value};
use std::sync::OnceLock;

use super::suggest::nearest;

/// Every cap here mirrors the `@spacefast/common` traffic-rule contract the
/// API enforces on the same values, so a rule that publishes from a file also
/// saves from the dashboard.
const RULE_LIMIT: usize = 50;
const DESCRIPTION_MAX_CHARS: usize = 255;
const NAME_MAX_CHARS: usize = 61;
/// Header / cookie / query parameter names a keyed condition addresses.
const KEY_MAX_CHARS: usize = 128;
const VALUE_MAX_CHARS: usize = 4096;
const VALUE_LIST_LIMIT: usize = 1000;

/// Request attributes a rule can look at. Keyed fields carry a name each.
const MATCH_FIELDS: [&str; 14] = [
    "path",
    "host",
    "method",
    "ip",
    "country",
    "asn",
    "userAgent",
    "referer",
    "header",
    "cookie",
    "query",
    "extension",
    "ja3",
    "ja4",
];
const KEYED_MATCH_FIELDS: [&str; 3] = ["header", "cookie", "query"];

/// Comparisons one rule makes, keyed names counted one each.
const CONDITION_LIMIT: usize = 50;

/// The published condition shapes, one per operator set.
const TEXT_CONDITION: &str = "trafficRuleTextCondition";
const OPTIONAL_TEXT_CONDITION: &str = "trafficRuleOptionalTextCondition";
const EXACT_CONDITION: &str = "trafficRuleExactCondition";
const ADDRESS_CONDITION: &str = "trafficRuleAddressCondition";
const EXTENSION_CONDITION: &str = "trafficRuleExtensionCondition";
const FINGERPRINT_CONDITION: &str = "trafficRuleFingerprintCondition";

const OPS: [&str; 9] = [
    "eq",
    "neq",
    "in",
    "inCidr",
    "contains",
    "startsWith",
    "endsWith",
    "wildcard",
    "exists",
];

/// What the edge can do with each attribute. The edge is not a general
/// matcher: a country is compared, never searched, and only a client address
/// knows what a CIDR range is. Declaring a comparison a field does not have is
/// a rule the provider refuses, so it is refused here — including through the
/// `*` shorthand, which would otherwise smuggle a wildcard into a field that
/// has none. The `@spacefast/common` contract carries the same table, and the
/// published schema names each field's operators from this one.
const EXACT_OPS: [&str; 3] = ["eq", "neq", "in"];
const TEXT_OPS: [&str; 7] = [
    "eq",
    "neq",
    "in",
    "contains",
    "startsWith",
    "endsWith",
    "wildcard",
];
const OPTIONAL_TEXT_OPS: [&str; 8] = [
    "eq",
    "neq",
    "in",
    "contains",
    "startsWith",
    "endsWith",
    "wildcard",
    "exists",
];
const ADDRESS_OPS: [&str; 4] = ["eq", "neq", "in", "inCidr"];
const EXTENSION_OPS: [&str; 4] = ["eq", "neq", "in", "exists"];
const FINGERPRINT_OPS: [&str; 3] = ["eq", "in", "exists"];

/// Every match field, the operators it compares with, and the definition its
/// condition publishes under. Fields sharing an operator set share a
/// definition, which keeps the published schema to six condition shapes
/// instead of one per field.
const FIELD_OPS: [(&str, &[&str], &str); 14] = [
    ("path", &TEXT_OPS, TEXT_CONDITION),
    ("host", &TEXT_OPS, TEXT_CONDITION),
    ("method", &EXACT_OPS, EXACT_CONDITION),
    ("ip", &ADDRESS_OPS, ADDRESS_CONDITION),
    ("country", &EXACT_OPS, EXACT_CONDITION),
    ("asn", &EXACT_OPS, EXACT_CONDITION),
    ("userAgent", &OPTIONAL_TEXT_OPS, OPTIONAL_TEXT_CONDITION),
    ("referer", &OPTIONAL_TEXT_OPS, OPTIONAL_TEXT_CONDITION),
    ("header", &OPTIONAL_TEXT_OPS, OPTIONAL_TEXT_CONDITION),
    ("cookie", &OPTIONAL_TEXT_OPS, OPTIONAL_TEXT_CONDITION),
    ("query", &OPTIONAL_TEXT_OPS, OPTIONAL_TEXT_CONDITION),
    ("extension", &EXTENSION_OPS, EXTENSION_CONDITION),
    ("ja3", &FINGERPRINT_OPS, FINGERPRINT_CONDITION),
    ("ja4", &FINGERPRINT_OPS, FINGERPRINT_CONDITION),
];

fn field_entry(
    field: &str,
) -> Option<&'static (&'static str, &'static [&'static str], &'static str)> {
    FIELD_OPS.iter().find(|(name, _, _)| *name == field)
}

fn field_ops(field: &str) -> &'static [&'static str] {
    field_entry(field).map_or(&[], |(_, ops, _)| *ops)
}

/// The operator a shorthand value stands for: a list is an `in`, a string with
/// a `*` is a wildcard, an `ip` value carrying a prefix is a range, and
/// anything else compares equal.
fn shorthand_op(field: &str, value: &Value) -> Option<&'static str> {
    let ranged = |text: &str| field == "ip" && text.contains('/');
    match value {
        Value::String(text) => Some(if ranged(text) {
            "inCidr"
        } else if text.contains('*') {
            "wildcard"
        } else {
            "eq"
        }),
        Value::Array(values) => Some(
            if values
                .iter()
                .any(|entry| entry.as_str().is_some_and(ranged))
            {
                "inCidr"
            } else {
                "in"
            },
        ),
        _ => None,
    }
}

/// A rule name addresses the rule in the API path, so it must stay a path
/// segment and must not read as one of that route's own verbs.
const RESERVED_RULE_NAMES: [&str; 6] = [
    "add",
    "validate",
    "preview",
    "bulk",
    "suspend-all",
    "restore-all",
];

/// The rule-name grammar, published in the schema and enforced by the
/// validator from this one string — a hand-rolled second copy of it is how the
/// documented grammar and the accepted one drift apart.
const NAME_PATTERN: &str = "^[a-z0-9][a-z0-9_-]{0,60}$";
const ALL_DIGITS_PATTERN: &str = "^[0-9]+$";

fn name_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| Regex::new(NAME_PATTERN).expect("static regex is valid"))
}

fn all_digits_pattern() -> &'static Regex {
    static PATTERN: OnceLock<Regex> = OnceLock::new();
    PATTERN.get_or_init(|| Regex::new(ALL_DIGITS_PATTERN).expect("static regex is valid"))
}

const CLAUSE_KEYS: [&str; 4] = ["op", "value", "values", "not"];
const FIREWALL_RULE_KEYS: [&str; 6] = ["name", "description", "action", "status", "match", "any"];
const CACHE_BYPASS_RULE_KEYS: [&str; 4] = ["name", "description", "match", "any"];

/// One step from the config root to the value that carried an issue. Both
/// lanes address the same value, each in its own path convention.
#[derive(Debug, Clone, PartialEq, Eq)]
enum Step {
    Field(String),
    Index(usize),
}

/// Why a declaration was rejected, and where. Codes are the config-shape codes
/// the rest of the compiler already publishes: the path names the offending
/// field, so a traffic rule needs no vocabulary of its own.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TrafficRuleIssue {
    pub code: &'static str,
    pub message: String,
    /// The known key an unknown one probably meant. The strict lane publishes
    /// it; the current lane's diagnostics carry no suggestion field.
    pub suggestion: Option<String>,
    path: Vec<Step>,
}

impl TrafficRuleIssue {
    /// `$.firewall[0].status` — the strict lane's JSON-path convention, which
    /// is also how the compiler keys its source locations.
    #[must_use]
    pub fn json_path(&self) -> String {
        format!("$.{}", self.label())
    }

    /// `firewall.0.status` — the current lane's dotted diagnostic path.
    #[must_use]
    pub fn dotted_path(&self) -> String {
        self.path
            .iter()
            .map(|step| match step {
                Step::Field(name) => name.clone(),
                Step::Index(index) => index.to_string(),
            })
            .collect::<Vec<_>>()
            .join(".")
    }

    fn label(&self) -> String {
        label(&self.path)
    }
}

/// The caps are the ones the zod contract enforces on the API path, and
/// JavaScript measures a string in UTF-16 code units. Counting anything else
/// would accept here what the dashboard rejects, or the reverse.
fn js_len(text: &str) -> usize {
    text.encode_utf16().count()
}

/// `firewall[0].match.country` — the path as it reads in a message, and as the
/// strict lane's `$.`-prefixed source-location keys spell it.
fn label(path: &[Step]) -> String {
    let mut out = String::new();
    for step in path {
        match step {
            Step::Field(name) => {
                if !out.is_empty() {
                    out.push('.');
                }
                out.push_str(name);
            }
            Step::Index(index) => out.push_str(&format!("[{index}]")),
        }
    }
    out
}

/// Validates the `firewall` and `cache` keys a config declares. Both are
/// optional; a config that declares neither produces no issues.
///
/// Nothing is returned but issues: the rules ride both config projections
/// verbatim, and the compiler that turns them into provider edge rules reads
/// them from there.
#[must_use]
pub fn validate(root: &Map<String, Value>) -> Vec<TrafficRuleIssue> {
    let mut checker = Checker::default();
    if let Some(declared) = root.get("firewall") {
        checker.at(Step::field("firewall"), |checker| {
            checker.rules(declared, RuleShape::Firewall);
        });
    }
    if let Some(declared) = root.get("cache") {
        checker.at(Step::field("cache"), |checker| checker.cache(declared));
    }
    checker.issues
}

/// Which rule keys a list accepts. A cache-bypass rule is a firewall rule
/// without the two keys that say what to answer with.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum RuleShape {
    Firewall,
    CacheBypass,
}

impl RuleShape {
    fn keys(self) -> &'static [&'static str] {
        match self {
            Self::Firewall => &FIREWALL_RULE_KEYS,
            Self::CacheBypass => &CACHE_BYPASS_RULE_KEYS,
        }
    }
}

impl Step {
    fn field(name: impl Into<String>) -> Self {
        Self::Field(name.into())
    }
}

#[derive(Default)]
struct Checker {
    path: Vec<Step>,
    issues: Vec<TrafficRuleIssue>,
}

impl Checker {
    /// Runs `body` with `step` appended to the path every issue is addressed by.
    fn at<T>(&mut self, step: Step, body: impl FnOnce(&mut Self) -> T) -> T {
        self.path.push(step);
        let out = body(self);
        self.path.pop();
        out
    }

    fn push(&mut self, code: &'static str, message: String, suggestion: Option<String>) {
        self.issues.push(TrafficRuleIssue {
            code,
            message,
            suggestion,
            path: self.path.clone(),
        });
    }

    fn invalid(&mut self, message: impl Into<String>) {
        self.push("config_invalid", message.into(), None);
    }

    fn invalid_at(&mut self, step: Step, message: impl Into<String>) {
        self.at(step, |checker| checker.invalid(message));
    }

    /// An unknown key, reported the way the strict lane reports every other
    /// one: at the key itself, with the nearest thing we do accept.
    fn unknown_key(&mut self, key: &str, known: &[&str]) {
        let suggestion = nearest(key, known);
        self.at(Step::field(key), |checker| {
            checker.push(
                "config_unknown_key",
                format!("Unknown config key {key}."),
                suggestion,
            );
        });
    }

    /// The path as it reads inside a message: `firewall[0].match.country`.
    fn label(&self) -> String {
        label(&self.path)
    }

    fn rules(&mut self, value: &Value, shape: RuleShape) {
        let Some(entries) = value.as_array() else {
            let label = self.label();
            self.invalid(format!("{label} must be an array of rules."));
            return;
        };
        if entries.len() > RULE_LIMIT {
            let label = self.label();
            self.invalid(format!("{label} supports at most {RULE_LIMIT} rules."));
        }
        // An explicit name addresses the rule in the API and at the provider,
        // so a list cannot spend one twice: the second rule would take the
        // first one's place instead of running beside it. Unnamed rules are
        // named after their own definition, so they only collide when they say
        // the same thing.
        let mut taken: Vec<&str> = Vec::new();
        for (index, entry) in entries.iter().enumerate() {
            self.at(Step::Index(index), |checker| checker.rule(entry, shape));
            let Some(name) = entry.get("name").and_then(Value::as_str) else {
                continue;
            };
            if taken.contains(&name) {
                self.at(Step::Index(index), |checker| {
                    checker.invalid_at(
                        Step::field("name"),
                        format!("{name} already names an earlier rule in this list."),
                    );
                });
            } else {
                taken.push(name);
            }
        }
    }

    fn cache(&mut self, value: &Value) {
        let Some(object) = value.as_object() else {
            self.invalid("cache must be an object.");
            return;
        };
        for key in object.keys() {
            if key != "bypass" {
                self.unknown_key(key, &["bypass"]);
            }
        }
        if let Some(bypass) = object.get("bypass") {
            self.at(Step::field("bypass"), |checker| {
                checker.rules(bypass, RuleShape::CacheBypass);
            });
        }
    }

    fn rule(&mut self, value: &Value, shape: RuleShape) {
        let Some(object) = value.as_object() else {
            let label = self.label();
            self.invalid(format!("{label} must be a rule object."));
            return;
        };
        for key in object.keys() {
            if !shape.keys().contains(&key.as_str()) {
                self.unknown_key(key, shape.keys());
            }
        }
        if let Some(name) = object.get("name") {
            self.at(Step::field("name"), |checker| checker.rule_name(name));
        }
        if let Some(description) = object.get("description") {
            let message = match description.as_str() {
                None => Some("A rule description is a string.".to_string()),
                Some(text) if js_len(text) > DESCRIPTION_MAX_CHARS => Some(format!(
                    "A rule description supports up to {DESCRIPTION_MAX_CHARS} characters."
                )),
                Some(_) => None,
            };
            if let Some(message) = message {
                self.invalid_at(Step::field("description"), message);
            }
        }
        if object.get("any").is_some_and(|any| !any.is_boolean()) {
            self.invalid_at(Step::field("any"), "any must be boolean.");
        }
        if shape == RuleShape::Firewall {
            self.action(object);
        }
        match object.get("match") {
            Some(declared) => self.at(Step::field("match"), |checker| {
                checker.match_conditions(declared);
            }),
            None => self.invalid_at(Step::field("match"), "A rule needs a match."),
        }
    }

    /// What a firewall rule answers with. `status` is a block's status, so a
    /// challenge carrying one is a rule whose author expected the other action.
    fn action(&mut self, rule: &Map<String, Value>) {
        let action = rule.get("action").and_then(Value::as_str);
        if !matches!(action, Some("block" | "challenge")) {
            self.invalid_at(
                Step::field("action"),
                "A firewall rule needs an action of \"block\" or \"challenge\".",
            );
            // Without an action there is no block-only rule to judge `status`
            // against; reporting both would name the same mistake twice.
            return;
        }
        let Some(status) = rule.get("status") else {
            return;
        };
        // `403.0` is the integer 403 to every JSON producer this config passes
        // through, and the crate's published contract already says so.
        if !status
            .as_f64()
            .is_some_and(|status| status.fract() == 0.0 && (400.0..=499.0).contains(&status))
        {
            self.invalid_at(
                Step::field("status"),
                "status must be an integer from 400 to 499.",
            );
            return;
        }
        if action != Some("block") {
            self.invalid_at(Step::field("status"), "status only applies to block.");
        }
    }

    fn rule_name(&mut self, value: &Value) {
        let Some(name) = value.as_str() else {
            self.invalid("A rule name must be a string.");
            return;
        };
        if !name_pattern().is_match(name) {
            self.invalid(format!(
                "A rule name is 1 to {NAME_MAX_CHARS} characters of lowercase letters, digits, - and _, starting with a letter or digit."
            ));
            return;
        }
        if all_digits_pattern().is_match(name) {
            self.invalid("A rule name cannot be all digits.");
            return;
        }
        if RESERVED_RULE_NAMES.contains(&name) {
            self.invalid(format!(
                "{name} is a reserved rule name: {} address the rules route itself.",
                RESERVED_RULE_NAMES.join(", ")
            ));
        }
    }

    fn match_conditions(&mut self, value: &Value) {
        let Some(object) = value.as_object() else {
            self.invalid("match must be an object of request conditions.");
            return;
        };
        if object.is_empty() {
            self.invalid("match needs at least one field.");
            return;
        }
        let mut conditions = 0;
        for (field, condition) in object {
            if KEYED_MATCH_FIELDS.contains(&field.as_str()) {
                conditions += condition.as_object().map_or(1, Map::len);
                self.at(Step::field(field), |checker| {
                    checker.keyed_conditions(condition, field);
                });
            } else if MATCH_FIELDS.contains(&field.as_str()) {
                conditions += 1;
                self.at(Step::field(field), |checker| {
                    checker.condition(condition, field);
                });
            } else {
                self.unknown_key(field, &MATCH_FIELDS);
            }
        }
        // The edge evaluates a bounded number of comparisons per rule, and a
        // keyed field spends one for every name it addresses.
        if conditions > CONDITION_LIMIT {
            self.invalid(format!(
                "A rule compares at most {CONDITION_LIMIT} conditions."
            ));
        }
    }

    fn keyed_conditions(&mut self, value: &Value, field: &str) {
        let Some(object) = value.as_object() else {
            let label = self.label();
            self.invalid(format!("{label} must be an object of name to condition."));
            return;
        };
        for (name, condition) in object {
            self.at(Step::field(name), |checker| {
                let length = js_len(name);
                if length == 0 || length > KEY_MAX_CHARS {
                    checker.invalid(format!(
                        "A {field} name is 1 to {KEY_MAX_CHARS} characters."
                    ));
                    return;
                }
                checker.condition(condition, field);
            });
        }
    }

    /// One condition: a string, a list of strings, or an explicit clause. The
    /// field decides which comparisons it may ask for, shorthands included.
    fn condition(&mut self, value: &Value, field: &str) {
        let shaped = self.issues.len();
        match value {
            Value::String(text) => self.text(text),
            Value::Array(values) => self.text_list(values),
            Value::Object(clause) => {
                self.clause(clause, field);
                return;
            }
            _ => {
                self.invalid(
                    "A match condition is a string, a list of strings, or an { op } clause.",
                );
                return;
            }
        }
        // A value that is not well formed has no operator to judge.
        if self.issues.len() != shaped {
            return;
        }
        if let Some(op) = shorthand_op(field, value) {
            self.field_op(field, op, true);
        }
    }

    /// The comparison a field may ask for. A `*` reads as a wildcard, so a
    /// field without one would silently compare against a literal star.
    fn field_op(&mut self, field: &str, op: &str, shorthand: bool) {
        let allowed = field_ops(field);
        if allowed.contains(&op) {
            return;
        }
        let allowed = allowed.join(", ");
        if shorthand && op == "wildcard" {
            self.invalid(format!(
                "A * in a {field} value reads as a wildcard, which {field} cannot do: it compares with {allowed}."
            ));
        } else {
            self.invalid(format!(
                "{field} cannot compare with {op}: it compares with {allowed}."
            ));
        }
    }

    fn text(&mut self, text: &str) {
        let length = js_len(text);
        if length == 0 || length > VALUE_MAX_CHARS {
            self.invalid(format!(
                "A match value is 1 to {VALUE_MAX_CHARS} characters."
            ));
        }
    }

    fn text_list(&mut self, values: &[Value]) {
        if values.is_empty() || values.len() > VALUE_LIST_LIMIT {
            self.invalid(format!(
                "A match list holds 1 to {VALUE_LIST_LIMIT} values."
            ));
            return;
        }
        for (index, value) in values.iter().enumerate() {
            self.at(Step::Index(index), |checker| match value.as_str() {
                Some(text) => checker.text(text),
                None => checker.invalid("A match list holds strings."),
            });
        }
    }

    fn clause(&mut self, clause: &Map<String, Value>, field: &str) {
        for key in clause.keys() {
            if !CLAUSE_KEYS.contains(&key.as_str()) {
                self.unknown_key(key, &CLAUSE_KEYS);
            }
        }
        let Some(op) = clause
            .get("op")
            .and_then(Value::as_str)
            .filter(|op| OPS.contains(op))
        else {
            self.invalid_at(
                Step::field("op"),
                format!("A clause op is one of {}.", OPS.join(", ")),
            );
            return;
        };
        if clause.get("not").is_some_and(|not| !not.is_boolean()) {
            self.invalid_at(Step::field("not"), "not must be boolean.");
        }
        let shaped = self.issues.len();
        if let Some(value) = clause.get("value") {
            self.at(Step::field("value"), |checker| match value.as_str() {
                Some(text) => checker.text(text),
                None => checker.invalid("A clause value is a string."),
            });
        }
        if let Some(values) = clause.get("values") {
            self.at(Step::field("values"), |checker| match values.as_array() {
                Some(values) => checker.text_list(values),
                None => checker.invalid("Clause values are a list of strings."),
            });
        }
        // The operator decides which of the two value fields the clause
        // carries, so a clause that names the wrong one — or none — is a rule
        // that never matches what its author meant. A clause whose values are
        // already malformed has no arity to judge.
        if self.issues.len() != shaped {
            return;
        }
        let value = clause.contains_key("value");
        let values = clause.contains_key("values");
        match op {
            "exists" => {
                if value {
                    self.invalid_at(Step::field("value"), "exists takes no value.");
                }
                if values {
                    self.invalid_at(Step::field("values"), "exists takes no values.");
                }
            }
            // Naming the wrong field is the whole mistake, so it is reported
            // alone: "in takes values, not value" already says what to write.
            "in" | "inCidr" => {
                if value {
                    self.invalid_at(
                        Step::field("value"),
                        format!("{op} takes values, not value."),
                    );
                } else if !values {
                    self.invalid_at(Step::field("values"), format!("{op} needs values."));
                }
            }
            _ => {
                if values {
                    self.invalid_at(
                        Step::field("values"),
                        format!("{op} takes value, not values."),
                    );
                } else if !value {
                    self.invalid_at(Step::field("value"), format!("{op} needs a value."));
                }
            }
        }
        // A clause that names the wrong value field has a mistake to fix
        // first; the field's own vocabulary is the next thing it meets.
        if self.issues.len() == shaped {
            self.field_op(field, op, false);
        }
    }
}

/// Adds the `firewall` and `cache` keys to a published schema root, together
/// with the `definitions` their `$ref`s resolve against.
///
/// One call instead of two spliced fragments: the match grammar is written
/// once and referenced, which is worth ~160 KB of published schema, and a lane
/// cannot publish a key whose definitions it forgot to carry.
#[must_use]
pub fn extend_json_schema(schema: Value) -> Value {
    let mut schema = schema;
    let root = schema
        .as_object_mut()
        .expect("a JSON-schema root is an object");
    root.entry("definitions")
        .or_insert_with(|| json!({}))
        .as_object_mut()
        .expect("definitions is an object")
        .extend(
            FIELD_OPS
                .iter()
                .map(|(_, ops, definition)| ((*definition).into(), condition_schema(ops)))
                .chain([(MATCH_DEFINITION.into(), match_schema())]),
        );
    root.entry("properties")
        .or_insert_with(|| json!({}))
        .as_object_mut()
        .expect("properties is an object")
        .extend([
            ("firewall".into(), firewall_schema()),
            ("cache".into(), cache_schema()),
        ]);
    schema
}

const MATCH_DEFINITION: &str = "trafficRuleMatch";

fn firewall_schema() -> Value {
    let mut rule = rule_schema();
    let properties = rule
        .pointer_mut("/properties")
        .and_then(Value::as_object_mut)
        .expect("the rule schema carries properties");
    properties.insert(
        "action".into(),
        json!({
            "enum": ["block", "challenge"],
            "description": "`block` answers without reaching the space; `challenge` asks for proof of a human."
        }),
    );
    properties.insert(
        "status".into(),
        json!({
            "type": "integer",
            "minimum": 400,
            "maximum": 499,
            "description": "Status a blocked request gets. Defaults to 403. Blocks only."
        }),
    );
    rule["required"] = json!(["action", "match"]);
    json!({
        "type": "array",
        "maxItems": RULE_LIMIT,
        "description": format!("Blocks or challenges requests before they reach the space. In order, at most {RULE_LIMIT}."),
        "items": rule
    })
}

fn cache_schema() -> Value {
    json!({
        "type": "object",
        "description": "Cache behavior for this space.",
        "properties": {
            "bypass": {
                "type": "array",
                "maxItems": RULE_LIMIT,
                "description": format!("Requests that skip the cache and are served fresh. In order, at most {RULE_LIMIT}."),
                "items": rule_schema()
            }
        },
        "additionalProperties": false
    })
}

/// The keys every rule carries. The firewall adds what it answers with.
fn rule_schema() -> Value {
    json!({
        "type": "object",
        "required": ["match"],
        "properties": {
            "name": {
                "type": "string",
                "pattern": NAME_PATTERN,
                "not": {
                    "anyOf": [
                        { "enum": RESERVED_RULE_NAMES },
                        { "pattern": ALL_DIGITS_PATTERN }
                    ]
                },
                "description": format!("Name for this rule: lowercase letters, digits, `-` and `_`, up to {NAME_MAX_CHARS} characters. Names the rule in the CLI and the API.")
            },
            "description": {
                "type": "string",
                "maxLength": DESCRIPTION_MAX_CHARS,
                "description": "What this rule is for."
            },
            "match": reference(MATCH_DEFINITION),
            "any": {
                "type": "boolean",
                "description": "Fire when any match field holds. Defaults to false: all of them have to."
            }
        },
        "additionalProperties": false
    })
}

fn reference(definition: &str) -> Value {
    json!({ "$ref": format!("#/definitions/{definition}") })
}

/// A reference that still carries a field-specific hint. Draft-07 ignores a
/// `$ref`'s siblings, so the annotation goes next to an `allOf` instead.
fn described_reference(definition: &str, description: &str) -> Value {
    json!({ "allOf": [reference(definition)], "description": description })
}

/// The definition a field's condition publishes under, so the field names the
/// operators it actually compares with.
fn condition_definition(field: &str) -> &'static str {
    field_entry(field).map_or(TEXT_CONDITION, |(_, _, definition)| *definition)
}

fn match_schema() -> Value {
    let condition = |field: &str| reference(condition_definition(field));
    let keyed = |field: &str| {
        json!({
            "type": "object",
            "description": "Per-name conditions: { \"x-api-client\": { \"op\": \"exists\" } }.",
            "propertyNames": { "minLength": 1, "maxLength": KEY_MAX_CHARS },
            "additionalProperties": condition(field)
        })
    };
    json!({
        "type": "object",
        "minProperties": 1,
        "description": "Request attributes the rule looks at. At least one.",
        "properties": {
            "path": condition("path"),
            "host": condition("host"),
            "method": condition("method"),
            "ip": condition("ip"),
            "country": described_reference(condition_definition("country"), "Two-letter country codes."),
            "asn": condition("asn"),
            "userAgent": condition("userAgent"),
            "referer": condition("referer"),
            "header": keyed("header"),
            "cookie": keyed("cookie"),
            "query": keyed("query"),
            "extension": described_reference(condition_definition("extension"), "Path extension, without the dot."),
            "ja3": condition("ja3"),
            "ja4": condition("ja4")
        },
        "additionalProperties": false
    })
}

fn condition_schema(ops: &[&str]) -> Value {
    let text = json!({ "type": "string", "minLength": 1, "maxLength": VALUE_MAX_CHARS });
    let list = json!({
        "type": "array",
        "minItems": 1,
        "maxItems": VALUE_LIST_LIMIT,
        "items": text
    });
    json!({
        "description": "What the field has to match. A string with a `*` in it matches as a wildcard, any other string matches exactly, and a list matches any of its values. An `ip` value with a `/` in it matches a CIDR range. Everything else is an explicit clause: { \"op\": \"exists\" }, { \"op\": \"contains\", \"value\": \"bot\" }, { \"op\": \"in\", \"values\": [\"RU\"], \"not\": true }.",
        "oneOf": [
            text,
            list,
            {
                "type": "object",
                "required": ["op"],
                "properties": {
                    "op": {
                        "enum": ops,
                        "description": "How the request attribute is compared to the value."
                    },
                    "value": {
                        "type": "string",
                        "minLength": 1,
                        "maxLength": VALUE_MAX_CHARS,
                        "description": "Single value to compare against."
                    },
                    "values": {
                        "type": "array",
                        "minItems": 1,
                        "maxItems": VALUE_LIST_LIMIT,
                        "items": { "type": "string", "minLength": 1, "maxLength": VALUE_MAX_CHARS },
                        "description": "Values to compare against, for `in` and `inCidr`."
                    },
                    "not": {
                        "type": "boolean",
                        "description": "Match when the comparison does not hold."
                    }
                },
                "additionalProperties": false
            }
        ]
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reported(value: Value) -> Vec<(&'static str, String, String, Option<String>)> {
        validate(value.as_object().expect("an object"))
            .into_iter()
            .map(|issue| {
                (
                    issue.code,
                    issue.json_path(),
                    issue.message,
                    issue.suggestion,
                )
            })
            .collect()
    }

    fn addressed(value: Value) -> Vec<(&'static str, String)> {
        reported(value)
            .into_iter()
            .map(|(code, path, _, _)| (code, path))
            .collect()
    }

    fn firewall(rule: Value) -> Value {
        json!({ "firewall": [rule] })
    }

    #[test]
    fn a_rule_declares_what_it_does_and_at_least_one_thing_to_match() {
        assert!(addressed(firewall(
            json!({ "action": "block", "match": { "path": "/x" } })
        ))
        .is_empty());
        assert_eq!(
            addressed(firewall(json!({ "match": { "path": "/x" } }))),
            [("config_invalid", "$.firewall[0].action".to_string())]
        );
        assert_eq!(
            addressed(firewall(
                json!({ "action": "deny", "match": { "path": "/x" } })
            )),
            [("config_invalid", "$.firewall[0].action".to_string())]
        );
        assert_eq!(
            addressed(firewall(json!({ "action": "block" }))),
            [("config_invalid", "$.firewall[0].match".to_string())]
        );
        assert_eq!(
            addressed(firewall(json!({ "action": "block", "match": {} }))),
            [("config_invalid", "$.firewall[0].match".to_string())]
        );
        // An unknown key names the nearest thing we do accept, the way every
        // other unknown config key does.
        assert_eq!(
            reported(firewall(
                json!({ "action": "block", "match": { "path": "/x", "cookies": "a" } })
            ))
            .into_iter()
            .map(|(code, path, _, suggestion)| (code, path, suggestion))
            .collect::<Vec<_>>(),
            [(
                "config_unknown_key",
                "$.firewall[0].match.cookies".to_string(),
                Some("cookie".to_string())
            )]
        );
    }

    #[test]
    fn a_status_belongs_to_a_block_and_only_inside_the_client_error_range() {
        let with = |status: Value| {
            addressed(firewall(
                json!({ "action": "block", "status": status, "match": { "path": "/x" } }),
            ))
        };
        assert!(with(json!(429)).is_empty());
        assert!(with(json!(429.0)).is_empty());
        for status in [json!(399), json!(500), json!(403.5), json!("403")] {
            assert_eq!(
                with(status.clone()),
                [("config_invalid", "$.firewall[0].status".to_string())],
                "{status} must be rejected"
            );
        }
        assert_eq!(
            addressed(firewall(
                json!({ "action": "challenge", "status": 403, "match": { "path": "/x" } })
            )),
            [("config_invalid", "$.firewall[0].status".to_string())]
        );
        // A rule with no usable action has no block-only rule to judge its
        // status against, so the missing action is reported once, alone.
        assert_eq!(
            addressed(firewall(
                json!({ "action": "deny", "status": 403, "match": { "path": "/x" } })
            )),
            [("config_invalid", "$.firewall[0].action".to_string())]
        );
    }

    #[test]
    fn a_rule_name_stays_an_addressable_path_segment() {
        let named = |name: &str| {
            addressed(firewall(json!({
                "name": name,
                "action": "block",
                "match": { "path": "/x" }
            })))
        };
        assert!(named("login-guard").is_empty());
        // Two rules cannot answer to one name; the second occurrence is the
        // one that has to change.
        assert_eq!(
            addressed(json!({ "firewall": [
                { "name": "guard", "action": "block", "match": { "path": "/a" } },
                { "name": "guard", "action": "block", "match": { "path": "/b" } }
            ] })),
            [("config_invalid", "$.firewall[1].name".to_string())]
        );
        for name in [
            "Login",
            "-guard",
            "login guard",
            "42",
            "bulk",
            "restore-all",
            "",
        ] {
            assert_eq!(
                named(name),
                [("config_invalid", "$.firewall[0].name".to_string())],
                "{name} must be rejected"
            );
        }
    }

    #[test]
    fn a_condition_is_a_string_a_list_or_a_clause() {
        let matching = |condition: Value| {
            addressed(firewall(
                json!({ "action": "block", "match": { "country": condition } }),
            ))
        };
        assert!(matching(json!("US")).is_empty());
        assert!(matching(json!(["US", "CA"])).is_empty());
        assert!(matching(json!({ "op": "in", "values": ["US"], "not": true })).is_empty());
        // The edge compares a country, it does not search one — through a
        // clause, and through the `*` shorthand that would read as a wildcard.
        assert_eq!(
            matching(json!({ "op": "contains", "value": "U" })),
            [("config_invalid", "$.firewall[0].match.country".to_string())]
        );
        assert_eq!(
            matching(json!("U*")),
            [("config_invalid", "$.firewall[0].match.country".to_string())]
        );
        // A range belongs to an address, and only a `/` makes one.
        assert!(addressed(firewall(
            json!({ "action": "block", "match": { "ip": "10.0.0.0/8" } })
        ))
        .is_empty());
        assert_eq!(
            addressed(firewall(json!({
                "action": "block",
                "match": { "path": { "op": "inCidr", "values": ["10.0.0.0/8"] } }
            }))),
            [("config_invalid", "$.firewall[0].match.path".to_string())]
        );
        assert_eq!(
            matching(json!(42)),
            [("config_invalid", "$.firewall[0].match.country".to_string())]
        );
        assert_eq!(
            matching(json!("")),
            [("config_invalid", "$.firewall[0].match.country".to_string())]
        );
        assert_eq!(
            matching(json!([])),
            [("config_invalid", "$.firewall[0].match.country".to_string())]
        );
        assert_eq!(
            matching(json!(["US", 7])),
            [(
                "config_invalid",
                "$.firewall[0].match.country[1]".to_string()
            )]
        );
    }

    #[test]
    fn a_clause_names_the_value_field_its_operator_reads() {
        let clause = |clause: Value| {
            addressed(firewall(
                json!({ "action": "block", "match": { "country": clause } }),
            ))
        };
        assert_eq!(
            clause(json!({ "op": "in", "value": "US" })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.value".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "in" })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.values".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "eq", "values": ["US"] })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.values".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "eq" })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.value".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "exists", "value": "US" })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.value".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "sounds-like", "value": "US" })),
            [(
                "config_invalid",
                "$.firewall[0].match.country.op".to_string()
            )]
        );
        assert_eq!(
            clause(json!({ "op": "eq", "value": "US", "caseInsensitive": true })),
            [(
                "config_unknown_key",
                "$.firewall[0].match.country.caseInsensitive".to_string()
            )]
        );
    }

    #[test]
    fn keyed_fields_address_the_name_that_carried_the_condition() {
        let keyed = |header: Value| {
            addressed(firewall(
                json!({ "action": "block", "match": { "header": header } }),
            ))
        };
        assert!(keyed(json!({ "x-api-client": { "op": "exists" } })).is_empty());
        assert_eq!(
            keyed(json!({ "x-api-client": 7 })),
            [(
                "config_invalid",
                "$.firewall[0].match.header.x-api-client".to_string()
            )]
        );
        assert_eq!(
            keyed(json!({ "": "bot" })),
            [("config_invalid", "$.firewall[0].match.header.".to_string())]
        );
        assert_eq!(
            keyed(json!("x-api-client")),
            [("config_invalid", "$.firewall[0].match.header".to_string())]
        );
    }

    #[test]
    fn each_list_is_an_array_of_rule_objects_within_its_cap() {
        assert_eq!(
            addressed(json!({ "firewall": {} })),
            [("config_invalid", "$.firewall".to_string())]
        );
        assert_eq!(
            addressed(firewall(json!("block /x"))),
            [("config_invalid", "$.firewall[0]".to_string())]
        );
        let many = (0..=RULE_LIMIT)
            .map(|index| json!({ "action": "block", "match": { "path": format!("/{index}") } }))
            .collect::<Vec<_>>();
        assert_eq!(
            addressed(json!({ "firewall": many })),
            [("config_invalid", "$.firewall".to_string())]
        );
        assert_eq!(
            addressed(firewall(
                json!({ "action": "block", "match": { "path": "/x" }, "priority": 1 })
            )),
            [("config_unknown_key", "$.firewall[0].priority".to_string())]
        );
        // The edge evaluates a bounded number of comparisons per rule, and a
        // keyed field spends one for every name it addresses.
        let headers = (0..=CONDITION_LIMIT)
            .map(|index| (format!("x-{index}"), json!("on")))
            .collect::<Map<_, _>>();
        assert_eq!(
            addressed(firewall(
                json!({ "action": "block", "match": { "header": headers } })
            )),
            [("config_invalid", "$.firewall[0].match".to_string())]
        );
    }

    #[test]
    fn cache_bypass_is_the_same_rule_without_an_action() {
        assert!(addressed(json!({
            "cache": { "bypass": [{ "name": "skip-preview", "match": { "query": { "preview": { "op": "exists" } } } }] }
        }))
        .is_empty());
        assert!(addressed(json!({ "cache": {} })).is_empty());
        assert_eq!(
            addressed(json!({ "cache": [] })),
            [("config_invalid", "$.cache".to_string())]
        );
        assert_eq!(
            addressed(json!({ "cache": { "ttl": 60 } })),
            [("config_unknown_key", "$.cache.ttl".to_string())]
        );
        assert_eq!(
            addressed(
                json!({ "cache": { "bypass": [{ "action": "block", "match": { "path": "/x" } }] } })
            ),
            [("config_unknown_key", "$.cache.bypass[0].action".to_string())]
        );
        assert_eq!(
            addressed(json!({ "cache": { "bypass": [{ "match": {} }] } })),
            [("config_invalid", "$.cache.bypass[0].match".to_string())]
        );
    }

    /// The strict lane's `$.`-prefixed paths are asserted throughout; this is
    /// the other rendering, which the current lane's diagnostics carry.
    #[test]
    fn a_dotted_path_spells_every_step_down_to_the_offending_field() {
        let issues = validate(
            json!({ "cache": { "bypass": [{ "match": { "country": { "op": "in", "value": "US" } } }] } })
                .as_object()
                .expect("an object"),
        );
        assert_eq!(
            issues
                .iter()
                .map(TrafficRuleIssue::dotted_path)
                .collect::<Vec<_>>(),
            ["cache.bypass.0.match.country.value"]
        );
    }

    #[test]
    fn the_published_schema_describes_the_grammar_the_validator_enforces() {
        let schema = extend_json_schema(json!({
            "type": "object",
            "properties": { "version": { "const": 1 } }
        }));
        // The root it extends keeps what it already published.
        assert_eq!(schema.pointer("/properties/version/const"), Some(&json!(1)));
        assert_eq!(
            schema.pointer("/properties/firewall/maxItems"),
            Some(&json!(RULE_LIMIT))
        );
        assert_eq!(
            schema.pointer("/properties/firewall/items/required"),
            Some(&json!(["action", "match"]))
        );
        assert_eq!(
            schema.pointer("/properties/firewall/items/properties/action/enum"),
            Some(&json!(["block", "challenge"]))
        );
        assert_eq!(
            schema.pointer("/properties/firewall/items/properties/status/maximum"),
            Some(&json!(499))
        );
        // The name refines are expressible in draft-07, so the published
        // grammar carries them instead of only the character class.
        assert_eq!(
            schema.pointer("/properties/firewall/items/properties/name/pattern"),
            Some(&json!(NAME_PATTERN))
        );
        assert_eq!(
            schema.pointer("/properties/firewall/items/properties/name/not/anyOf/0/enum"),
            Some(&json!(RESERVED_RULE_NAMES))
        );
        assert_eq!(
            schema.pointer("/properties/cache/properties/bypass/maxItems"),
            Some(&json!(RULE_LIMIT))
        );
        assert_eq!(
            schema.pointer("/properties/cache/properties/bypass/items/required"),
            Some(&json!(["match"]))
        );
        // Every field publishes the operators it compares with, through the
        // definition its operator set shares.
        for (field, ops, definition) in FIELD_OPS {
            let published = schema.pointer(&format!(
                "/definitions/{definition}/oneOf/2/properties/op/enum"
            ));
            assert_eq!(published, Some(&json!(ops)), "{field} publishes its ops");
        }
        assert_eq!(
            schema.pointer("/definitions/trafficRuleMatch/properties/ja4/$ref"),
            Some(&json!(format!("#/definitions/{FINGERPRINT_CONDITION}")))
        );
        assert_eq!(
            schema.pointer(
                "/definitions/trafficRuleMatch/properties/header/additionalProperties/$ref"
            ),
            Some(&json!(format!("#/definitions/{OPTIONAL_TEXT_CONDITION}")))
        );
        // Every `$ref` the fragment writes resolves inside the schema it was
        // added to: a key whose grammar is missing documents nothing.
        let mut pointers = Vec::new();
        collect_references(&schema, &mut pointers);
        assert!(pointers.len() >= MATCH_FIELDS.len());
        for pointer in pointers {
            let resolved = pointer
                .strip_prefix('#')
                .and_then(|pointer| schema.pointer(pointer));
            assert!(resolved.is_some(), "{pointer} must resolve");
        }
    }

    fn collect_references(value: &Value, out: &mut Vec<String>) {
        match value {
            Value::Object(object) => {
                for (key, child) in object {
                    match (key.as_str(), child.as_str()) {
                        ("$ref", Some(pointer)) => out.push(pointer.to_string()),
                        _ => collect_references(child, out),
                    }
                }
            }
            Value::Array(values) => {
                for child in values {
                    collect_references(child, out);
                }
            }
            _ => {}
        }
    }
}
