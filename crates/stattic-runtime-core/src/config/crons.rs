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
    /// What the author wrote.
    pub schedule: String,
    /// What the provider runs: `schedule` itself, or the closest basic
    /// cadence for a crontab.
    pub basic_schedule: String,
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
    /// A five-field crontab, kept as written and run at its closest basic
    /// cadence. A warning: the publish goes ahead.
    ScheduleConverted {
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
            Self::ScheduleConverted { .. } => "config_cron_schedule_converted",
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
            | Self::DuplicatePath { message, .. }
            | Self::ScheduleConverted { message, .. } => message.clone(),
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
            Self::InvalidSchedule { index, .. } | Self::ScheduleConverted { index, .. } => {
                Some((*index, "schedule"))
            }
        }
    }

    /// Only a converted crontab lets the publish continue.
    #[must_use]
    pub fn is_error(&self) -> bool {
        !matches!(self, Self::ScheduleConverted { .. })
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
                    "description": "hourly, daily, twicedaily, weekly, or an Nh/Nd/Nw count (N times per hour, day or week). A five-field crontab runs at its closest basic cadence, with a warning."
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
        let (schedule, basic_schedule) = match cron_schedule(entry) {
            Ok(pair) => pair,
            Err(message) => {
                issues.push(CronIssue::InvalidSchedule { index, message });
                continue;
            }
        };
        if schedule != basic_schedule {
            issues.push(CronIssue::ScheduleConverted {
                index,
                message: format!(
                    "{path} runs {basic_schedule}: Spacefast runs the crontab {schedule} at its closest basic schedule, at times the provider picks."
                ),
            });
        }
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
            basic_schedule,
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

/// The schedule as written, and the basic schedule the provider runs.
fn cron_schedule(entry: &Map<String, Value>) -> Result<(String, String), String> {
    let Some(schedule) = entry.get("schedule").and_then(Value::as_str) else {
        return Err("crons entries require a schedule string.".into());
    };
    if schedule_is_valid(schedule) {
        return Ok((schedule.to_string(), schedule.to_string()));
    }
    if let Some(basic) = crontab_basic_schedule(schedule) {
        return Ok((schedule.to_string(), basic));
    }
    Err(format!(
        "The crons schedule {schedule} must be hourly, daily, twicedaily, weekly, an Nh/Nd/Nw count, or a numeric five-field crontab."
    ))
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

/// The provider's basic schedule grammar: named intervals or `Nh`/`Nd`/`Nw`
/// counts (N runs per hour, day or week). The provider runs a five-field
/// crontab only with its advanced cron, which the platform does not use: a
/// per-minute expression multiplied across a fleet floods the provider's job
/// queue. `crontab_basic_schedule` maps a crontab onto this grammar instead.
/// The TypeScript config schema holds the same grammar.
#[must_use]
pub fn schedule_is_valid(schedule: &str) -> bool {
    if matches!(schedule, "hourly" | "daily" | "twicedaily" | "weekly") {
        return true;
    }
    let Some(unit) = schedule.chars().last() else {
        return false;
    };
    let limit = match unit {
        'h' => 12u32,
        'd' => 23,
        'w' => 6,
        _ => return false,
    };
    let digits = &schedule[..schedule.len() - 1];
    !digits.is_empty()
        && digits.bytes().all(|byte| byte.is_ascii_digit())
        && digits
            .parse::<u32>()
            .is_ok_and(|count| (1..=limit).contains(&count))
}

/// The closest basic schedule for a numeric five-field crontab, or `None` when
/// the expression is not one. Keeps the cadence, never more often than the
/// basic grammar allows (12 runs an hour); the provider picks the times.
#[must_use]
pub fn crontab_basic_schedule(schedule: &str) -> Option<String> {
    if schedule.len() > 256
        || !schedule
            .bytes()
            .all(|byte| byte.is_ascii_digit() || b"*,/- ".contains(&byte))
    {
        return None;
    }
    let fields: Vec<&str> = schedule.split(' ').collect();
    let [minute, hour, day_of_month, month, day_of_week] = fields.as_slice() else {
        return None;
    };
    let minutes = crontab_field_values(minute, 0, 59)?.len();
    let hours = crontab_field_values(hour, 0, 23)?.len();
    let dates = crontab_field_values(day_of_month, 1, 31)?.len();
    let months = crontab_field_values(month, 1, 12)?.len();
    // Sunday is both 0 and 7.
    let weekdays = crontab_field_values(day_of_week, 0, 7)?
        .into_iter()
        .map(|day| day % 7)
        .collect::<std::collections::BTreeSet<_>>()
        .len();
    let basic = if hours == 24 {
        if minutes == 1 {
            "hourly".to_string()
        } else {
            format!("{}h", minutes.min(12))
        }
    } else if dates < 31 || months < 12 {
        // Monthly and yearly cadences have no basic form; weekly is closest.
        "weekly".to_string()
    } else {
        let per_day = hours * minutes;
        if weekdays < 7 && per_day == 1 {
            if weekdays == 1 {
                "weekly".to_string()
            } else {
                format!("{weekdays}w")
            }
        } else {
            match per_day {
                1 => "daily".to_string(),
                2 => "twicedaily".to_string(),
                count => format!("{}d", count.min(23)),
            }
        }
    };
    Some(basic)
}

/// The values a crontab field selects, or `None` when it is malformed.
fn crontab_field_values(
    field: &str,
    min: u32,
    max: u32,
) -> Option<std::collections::BTreeSet<u32>> {
    let mut selected = std::collections::BTreeSet::new();
    for part in field.split(',') {
        let mut stepped = part.split('/');
        let base = stepped.next()?;
        let step = match stepped.next() {
            Some(step) => parse_field_number(step).filter(|step| *step >= 1)?,
            None => 1,
        };
        if stepped.next().is_some() {
            return None;
        }
        let (start, end) = if base == "*" {
            (min, max)
        } else {
            let mut range = base.split('-');
            let start = parse_field_number(range.next()?)?;
            let end = match range.next() {
                Some(end) => parse_field_number(end)?,
                None if part.contains('/') => max,
                None => start,
            };
            if range.next().is_some() {
                return None;
            }
            (start, end)
        };
        if start < min || end > max || start > end {
            return None;
        }
        selected.extend((start..=end).step_by(usize::try_from(step).ok()?));
    }
    (!selected.is_empty()).then_some(selected)
}

fn parse_field_number(text: &str) -> Option<u32> {
    if text.is_empty() || !text.bytes().all(|byte| byte.is_ascii_digit()) {
        return None;
    }
    text.parse().ok()
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
                "schedule": entry.basic_schedule,
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
    fn accepted_schedules_cover_named_and_shorthand_forms() {
        for schedule in [
            "hourly",
            "daily",
            "twicedaily",
            "weekly",
            "1h",
            "12h",
            "23d",
            "6w",
        ] {
            assert!(schedule_is_valid(schedule), "{schedule} must be accepted");
        }
    }

    #[test]
    fn rejected_schedules_cover_crontab_expressions_bounds_and_macros() {
        for schedule in [
            "",
            "0h",
            "13h",
            "24d",
            "7w",
            "1H",
            "hourly ",
            "@daily",
            "nightly",
            // Crontabs are not basic; validate maps them instead.
            "*/5 * * * *",
        ] {
            assert!(!schedule_is_valid(schedule), "{schedule} must be rejected");
        }
    }

    #[test]
    fn crontabs_run_at_their_closest_basic_schedule() {
        for (crontab, basic) in [
            ("0 * * * *", "hourly"),
            ("17 * * * *", "hourly"),
            ("*/5 * * * *", "12h"),
            ("* * * * *", "12h"),
            ("*/15 * * * *", "4h"),
            ("*/30 * * * *", "2h"),
            ("3,33 * * * *", "2h"),
            ("0 7 * * *", "daily"),
            ("0 1,13 * * *", "twicedaily"),
            ("0 */3 * * *", "8d"),
            ("*/10 17-23 * * 2,3", "23d"),
            ("0 11 * * 1-5", "5w"),
            ("30 14 * * 4", "weekly"),
            ("0 9 * * 0,7", "weekly"),
            ("0 0 1 * *", "weekly"),
        ] {
            assert_eq!(
                crontab_basic_schedule(crontab).as_deref(),
                Some(basic),
                "{crontab}"
            );
        }
        for malformed in [
            "60 * * * *",
            "0 24 * * *",
            "*/0 * * * *",
            "5-2 * * * *",
            "0 * * *",
            "0 7 * * MON",
            "0  7 * * *",
        ] {
            assert_eq!(crontab_basic_schedule(malformed), None, "{malformed}");
        }

        let (entries, issues) =
            validate(&json!([{ "path": "/api/tick", "schedule": "*/5 * * * *" }]));
        assert_eq!(
            issues.iter().map(CronIssue::code).collect::<Vec<_>>(),
            ["config_cron_schedule_converted"]
        );
        assert!(!issues[0].is_error());
        assert_eq!(entries[0].schedule, "*/5 * * * *");
        assert_eq!(
            artifact(&entries).and_then(|value| value.pointer("/crons/0/schedule").cloned()),
            Some(json!("12h"))
        );
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
            { "path": "/api/cron/growth-report", "schedule": "daily" },
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
                        "schedule": "daily"
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
