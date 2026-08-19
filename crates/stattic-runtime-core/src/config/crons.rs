//! The `crons` config key: scheduled requests the provider crontab issues
//! against this version's own paths.
//!
//! One grammar, validated once, used by both config lanes and by the artifact
//! the serving engine reads. Schedules pass through verbatim — the provider
//! expands them, and normalizing here would publish a schedule the author
//! never wrote.

use serde_json::{json, Map, Value};

use crate::protocol::{CONFIG_CRON_LIMIT, CONFIG_CRON_PATH_MAX_CHARS};

/// One accepted declaration. `key` is derived from `path`, so nothing
/// downstream has to re-derive it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CronEntry {
    pub key: String,
    pub path: String,
    pub schedule: String,
}

/// Why a declaration was rejected. One variant per published issue code; the
/// index addresses the entry so both lanes can spell their own path for it.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum CronIssue {
    /// `crons` is not an array at all.
    NotAnArray,
    TooMany,
    InvalidPath {
        index: usize,
        message: String,
    },
    InvalidSchedule {
        index: usize,
        message: String,
    },
    DuplicatePath {
        index: usize,
        message: String,
    },
}

impl CronIssue {
    #[must_use]
    pub fn code(&self) -> &'static str {
        match self {
            Self::NotAnArray => "config_invalid",
            Self::TooMany => "config_cron_too_many",
            Self::InvalidPath { .. } => "config_cron_invalid_path",
            Self::InvalidSchedule { .. } => "config_cron_invalid_schedule",
            Self::DuplicatePath { .. } => "config_cron_duplicate_path",
        }
    }

    #[must_use]
    pub fn message(&self) -> String {
        match self {
            Self::NotAnArray => "crons must be an array of { path, schedule } objects.".to_string(),
            Self::TooMany => {
                format!("crons supports at most {CONFIG_CRON_LIMIT} entries.")
            }
            Self::InvalidPath { message, .. }
            | Self::InvalidSchedule { message, .. }
            | Self::DuplicatePath { message, .. } => message.clone(),
        }
    }

    /// The entry this issue is about, and the field within it. `None` means the
    /// whole `crons` key.
    #[must_use]
    pub fn location(&self) -> Option<(usize, &'static str)> {
        match self {
            Self::NotAnArray | Self::TooMany => None,
            Self::InvalidPath { index, .. } | Self::DuplicatePath { index, .. } => {
                Some((*index, "path"))
            }
            Self::InvalidSchedule { index, .. } => Some((*index, "schedule")),
        }
    }
}

/// The JSON-schema fragment for the `crons` key. Both config lanes publish it
/// verbatim, so the grammar is documented once, next to the validator that
/// enforces it.
#[must_use]
pub fn json_schema() -> Value {
    json!({
        "type": "array",
        "maxItems": CONFIG_CRON_LIMIT,
        "description": "Scheduled requests Spacefast issues against this space's own paths.",
        "items": {
            "type": "object",
            "properties": {
                "path": {
                    "type": "string",
                    "pattern": "^/[^*:?#\\s\\\\]*$",
                    "maxLength": CONFIG_CRON_PATH_MAX_CHARS,
                    "description": "Literal path to request, starting with /. No wildcards, placeholders, query or fragment."
                },
                "schedule": {
                    "type": "string",
                    "description": "hourly, daily, twicedaily, weekly, an Nh/Nd/Nw interval, or a five-field crontab expression."
                }
            },
            "required": ["path", "schedule"],
            "additionalProperties": false
        }
    })
}

/// Validates a `crons` value and returns the entries it declares. Entries are
/// returned in declaration order; an entry that produced an issue is dropped.
pub fn validate(value: &Value) -> (Vec<CronEntry>, Vec<CronIssue>) {
    let mut issues = Vec::new();
    let Some(entries) = value.as_array() else {
        return (Vec::new(), vec![CronIssue::NotAnArray]);
    };
    if entries.len() > CONFIG_CRON_LIMIT {
        issues.push(CronIssue::TooMany);
    }
    let mut accepted: Vec<CronEntry> = Vec::new();
    for (index, entry) in entries.iter().enumerate() {
        let Some(entry) = entry.as_object() else {
            issues.push(CronIssue::InvalidPath {
                index,
                message: "Each crons entry must be an object with path and schedule.".into(),
            });
            continue;
        };
        let path = match cron_path(entry) {
            Ok(path) => path,
            Err(message) => {
                issues.push(CronIssue::InvalidPath { index, message });
                continue;
            }
        };
        let schedule = match cron_schedule(entry) {
            Ok(schedule) => schedule,
            Err(message) => {
                issues.push(CronIssue::InvalidSchedule { index, message });
                continue;
            }
        };
        let key = cron_key(&path);
        if let Some(previous) = accepted
            .iter()
            .find(|previous| previous.path == path || previous.key == key)
        {
            let message = if previous.path == path {
                format!("crons declares {path} more than once.")
            } else {
                // Distinct paths that slug to one key would give two schedules
                // one identity downstream, which is the same defect as writing
                // the path twice.
                format!(
                    "crons entries {} and {path} both compile to the cron key {key}.",
                    previous.path
                )
            };
            issues.push(CronIssue::DuplicatePath { index, message });
            continue;
        }
        accepted.push(CronEntry {
            key,
            path,
            schedule,
        });
    }
    (accepted, issues)
}

fn cron_path(entry: &Map<String, Value>) -> Result<String, String> {
    let Some(path) = entry.get("path").and_then(Value::as_str) else {
        return Err("crons entries require a path string.".into());
    };
    if path.chars().count() > CONFIG_CRON_PATH_MAX_CHARS {
        return Err(format!(
            "A crons path supports up to {CONFIG_CRON_PATH_MAX_CHARS} characters."
        ));
    }
    if !path.starts_with('/') {
        return Err(format!("The crons path {path} must start with /."));
    }
    if path.contains(['?', '#']) {
        return Err(format!(
            "The crons path {path} must not carry a query or fragment."
        ));
    }
    if path.contains(['*', ':']) {
        return Err(format!(
            "The crons path {path} must be a literal path: no * splats and no :placeholder segments."
        ));
    }
    let literal_path = crate::prepare::variable_pattern().replace_all(path, "variable");
    if literal_path
        .chars()
        .any(|character| character.is_whitespace() || character.is_control() || character == '\\')
    {
        return Err(format!(
            "The crons path {path} must not contain whitespace, control characters or backslashes."
        ));
    }
    if cron_key(path).is_empty() {
        return Err(format!(
            "The crons path {path} must contain at least one letter or digit."
        ));
    }
    Ok(path.to_string())
}

fn cron_schedule(entry: &Map<String, Value>) -> Result<String, String> {
    let Some(schedule) = entry.get("schedule").and_then(Value::as_str) else {
        return Err("crons entries require a schedule string.".into());
    };
    if !schedule_is_valid(schedule) {
        return Err(format!(
            "The crons schedule {schedule} must be hourly, daily, twicedaily, weekly, an Nh/Nd/Nw interval, or a five-field crontab expression."
        ));
    }
    Ok(schedule.to_string())
}

/// The artifact key for a path: lowercase, leading `/` stripped, every run of
/// non-alphanumerics collapsed to one `-`, trimmed.
#[must_use]
pub fn cron_key(path: &str) -> String {
    let mut key = String::with_capacity(path.len());
    for character in path.trim_start_matches('/').chars() {
        if character.is_ascii_alphanumeric() {
            key.push(character.to_ascii_lowercase());
        } else if !key.ends_with('-') {
            key.push('-');
        }
    }
    key.trim_matches('-').to_string()
}

/// The wp.cloud schedule grammar: named intervals, `Nh`/`Nd`/`Nw` shorthand, or
/// a five-field crontab expression. No `@daily` macros and no month/day names —
/// the provider does not accept them either.
#[must_use]
pub fn schedule_is_valid(schedule: &str) -> bool {
    if matches!(schedule, "hourly" | "daily" | "twicedaily" | "weekly") {
        return true;
    }
    if let Some(unit) = schedule.chars().last() {
        let limit = match unit {
            'h' => Some(12u32),
            'd' => Some(23),
            'w' => Some(6),
            _ => None,
        };
        if let Some(limit) = limit {
            let digits = &schedule[..schedule.len() - 1];
            return !digits.is_empty()
                && digits.bytes().all(|byte| byte.is_ascii_digit())
                && digits
                    .parse::<u32>()
                    .is_ok_and(|count| (1..=limit).contains(&count));
        }
    }
    crontab_expression_is_valid(schedule)
}

/// Field bounds, in crontab order: minute, hour, day of month, month, weekday.
const CRON_FIELD_BOUNDS: [(u32, u32); 5] = [(0, 59), (0, 23), (1, 31), (1, 12), (0, 7)];

fn crontab_expression_is_valid(schedule: &str) -> bool {
    let fields = schedule.split(' ').collect::<Vec<_>>();
    fields.len() == 5
        && fields
            .iter()
            .zip(CRON_FIELD_BOUNDS)
            .all(|(field, bounds)| cron_field_is_valid(field, bounds))
}

fn cron_field_is_valid(field: &str, (min, max): (u32, u32)) -> bool {
    !field.is_empty()
        && field.split(',').all(|item| {
            let (range, step) = match item.split_once('/') {
                Some((range, step)) => (range, Some(step)),
                None => (item, None),
            };
            // A step only makes sense over a span, which is what `*` and `a-b`
            // are; `5/2` names one value and then asks to skip within it.
            let span = match range {
                "*" => true,
                _ => match range.split_once('-') {
                    Some((start, end)) => {
                        let (Some(start), Some(end)) =
                            (cron_number(start, min, max), cron_number(end, min, max))
                        else {
                            return false;
                        };
                        if start > end {
                            return false;
                        }
                        true
                    }
                    None => {
                        if cron_number(range, min, max).is_none() {
                            return false;
                        }
                        false
                    }
                },
            };
            match step {
                None => true,
                Some(step) => {
                    span && !step.is_empty()
                        && step.bytes().all(|byte| byte.is_ascii_digit())
                        && step.parse::<u32>().is_ok_and(|step| step >= 1)
                }
            }
        })
}

fn cron_number(value: &str, min: u32, max: u32) -> Option<u32> {
    (!value.is_empty() && value.bytes().all(|byte| byte.is_ascii_digit()))
        .then(|| value.parse::<u32>().ok())
        .flatten()
        .filter(|number| (min..=max).contains(number))
}

/// The generated runtime file, or `None` when the version declares no crons.
#[must_use]
pub fn artifact(entries: &[CronEntry]) -> Option<Value> {
    if entries.is_empty() {
        return None;
    }
    Some(json!({
        "version": crate::protocol::CRONS_ARTIFACT_VERSION,
        "crons": entries
            .iter()
            .map(|entry| json!({
                "key": entry.key,
                "path": entry.path,
                "schedule": entry.schedule,
            }))
            .collect::<Vec<_>>(),
    }))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn codes(value: Value) -> Vec<&'static str> {
        validate(&value).1.iter().map(CronIssue::code).collect()
    }

    #[test]
    fn accepted_schedules_cover_named_shorthand_and_crontab_forms() {
        for schedule in [
            "hourly",
            "daily",
            "twicedaily",
            "weekly",
            "1h",
            "12h",
            "23d",
            "6w",
            "0 7 * * *",
            "*/5 * * * *",
            "0,30 0-23/2 1-31 1,6,12 0-7",
            "59 23 31 12 7",
        ] {
            assert!(schedule_is_valid(schedule), "{schedule} must be accepted");
        }
    }

    #[test]
    fn rejected_schedules_cover_bounds_macros_names_and_malformed_fields() {
        for schedule in [
            "",
            "0h",
            "13h",
            "24d",
            "7w",
            "1H",
            "hourly ",
            "@daily",
            "0 7 * *",
            "0 7 * * * *",
            "0  7 * * *",
            "jan 7 * * *",
            "0 7 * jan *",
            "0 7 * * mon",
            "60 * * * *",
            "* 24 * * *",
            "* * 0 * *",
            "* * * 13 *",
            "* * * * 8",
            "5-1 * * * *",
            "*/0 * * * *",
            "5/2 * * * *",
            "0, * * * *",
            "*/ * * * *",
            "-5 * * * *",
        ] {
            assert!(!schedule_is_valid(schedule), "{schedule} must be rejected");
        }
    }

    #[test]
    fn keys_slug_the_path_and_collapse_punctuation_runs() {
        assert_eq!(
            cron_key("/api/cron/growth-report"),
            "api-cron-growth-report"
        );
        assert_eq!(cron_key("/API/Daily_Job.php"), "api-daily-job-php");
        assert_eq!(cron_key("/"), "");
    }

    #[test]
    fn every_issue_code_fires_for_its_own_reason() {
        assert_eq!(codes(json!({})), ["config_invalid"]);
        assert_eq!(
            codes(json!([{ "path": "/a", "schedule": "nightly" }])),
            ["config_cron_invalid_schedule"]
        );
        for path in [
            "api/x",
            "/api/*",
            "/api/:id",
            "/api?x=1",
            "/api#top",
            "/api/white space",
            "/",
        ] {
            assert_eq!(
                codes(json!([{ "path": path, "schedule": "daily" }])),
                ["config_cron_invalid_path"],
                "{path} must be rejected"
            );
        }
        assert!(validate(&json!([
            { "path": "/api/{{ vars.SLUG }}", "schedule": "daily" }
        ]))
        .1
        .is_empty());
        let many = (0..=CONFIG_CRON_LIMIT)
            .map(|index| json!({ "path": format!("/job/{index}"), "schedule": "daily" }))
            .collect::<Vec<_>>();
        assert_eq!(codes(json!(many)), ["config_cron_too_many"]);
        assert_eq!(
            codes(json!([
                { "path": "/a/b", "schedule": "daily" },
                { "path": "/a/b", "schedule": "hourly" },
            ])),
            ["config_cron_duplicate_path"]
        );
        // Distinct paths, one key: the same identity defect, the same code.
        assert_eq!(
            codes(json!([
                { "path": "/a/b", "schedule": "daily" },
                { "path": "/a-b", "schedule": "hourly" },
            ])),
            ["config_cron_duplicate_path"]
        );
    }

    #[test]
    fn the_artifact_carries_verbatim_schedules_and_only_when_declared() {
        assert_eq!(artifact(&[]), None);
        let (entries, issues) = validate(&json!([
            { "path": "/api/cron/growth-report", "schedule": "0 7 * * *" },
            { "path": "/api/cron/digest", "schedule": "twicedaily" },
        ]));
        assert!(issues.is_empty(), "{issues:?}");
        assert_eq!(
            artifact(&entries),
            Some(json!({
                "version": 1,
                "crons": [
                    {
                        "key": "api-cron-growth-report",
                        "path": "/api/cron/growth-report",
                        "schedule": "0 7 * * *"
                    },
                    {
                        "key": "api-cron-digest",
                        "path": "/api/cron/digest",
                        "schedule": "twicedaily"
                    }
                ]
            }))
        );
    }
}
