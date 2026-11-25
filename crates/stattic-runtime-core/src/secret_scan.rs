//! Tiered secret scanning for both native site finalization (over CAS blobs)
//! and WASI pre-upload inspection (`prepare_abi::Operation::ScanSecrets`).
//!
//! Combines the vendored upstream Gitleaks rules in `spacefast-secret-scan`
//! with Spacefast-native Tier-1 Block credential rules, `sf.jsonc` allowlists,
//! incremental catalog caching, and strict redaction.

use serde::{Deserialize, Serialize};
use serde_json::{json, Value};
use spacefast_secret_scan::{
    AllowlistConfig, AllowlistEntry, AllowlistOverride, Finding, RuleConfig, ScanConfig,
    ScanLimits, Scanner, Severity,
};
use std::sync::LazyLock;

use crate::hash::stable_json_sha256;

/// Bump whenever Spacefast's wrapper rules or scan semantics change so
/// previously validated catalogs fail closed and rescan.
pub(crate) const SECRET_SCAN_WRAPPER_REVISION: u32 = 5;

/// Hard caps for `sf.jsonc` `security.secrets.allowlist`.
pub const CONFIG_SECRET_ALLOWLIST_LIMIT: usize = 128;

/// Hard budgets for one finalize run so huge versions stay well inside the
/// runtime finalizer's wall-clock ceiling.
#[cfg(not(target_family = "wasm"))]
const FINALIZE_MAX_FILE_BYTES: usize = 4 * 1024 * 1024;
#[cfg(not(target_family = "wasm"))]
const FINALIZE_MAX_TOTAL_BYTES: usize = 64 * 1024 * 1024;
#[cfg(not(target_family = "wasm"))]
const FINALIZE_MAX_WARNINGS: usize = 50;
#[cfg(not(target_family = "wasm"))]
const FINALIZE_TIME_BUDGET_SECS: u64 = 15;

/// Spacefast-native high-confidence secret rules added on top of upstream
/// Gitleaks defaults.
#[must_use]
pub fn spacefast_native_rules() -> Vec<RuleConfig> {
    vec![
        RuleConfig {
            id: "spacefast-api-token".into(),
            description: "Detected a Spacefast API, account, space, or view access token.".into(),
            regex: Some(
                r"\b((?:sf_(?:live|test|pat|key)|sfa|sfm|sfc|sfv)_[A-Za-z0-9_-]{20,})\b".into(),
            ),
            path: None,
            secret_group: Some(1),
            entropy: Some(3.0),
            keywords: vec![
                "sf_live_".into(),
                "sf_test_".into(),
                "sf_pat_".into(),
                "sf_key_".into(),
                "sfa_".into(),
                "sfm_".into(),
                "sfc_".into(),
                "sfv_".into(),
            ],
            allowlist: None,
            allowlists: Vec::new(),
            severity: Some(Severity::Block),
        },
        RuleConfig {
            id: "spacefast-claim-url".into(),
            description:
                "Detected a Spacefast claim link or space key URL, which grants control of an unclaimed Space."
                    .into(),
            regex: Some(
                r"(?i)https?://[a-z0-9.-]*spacefast\.[a-z0-9.-]+/(?:(?:claim|c)[/#]|__/)([A-Za-z0-9_-]{24,})"
                    .into(),
            ),
            path: None,
            secret_group: Some(1),
            entropy: Some(3.2),
            keywords: vec![
                "/claim/".into(),
                "/claim#".into(),
                "/c/".into(),
                "/c#".into(),
                "/__/".into(),
            ],
            allowlist: None,
            allowlists: vec![AllowlistConfig {
                description: Some("Ignore placeholder claim examples".into()),
                regexes: vec![r"(?i)(?:example|placeholder|your[_-]?token)".into()],
                ..Default::default()
            }],
            severity: Some(Severity::Block),
        },
        RuleConfig {
            id: "spacefast-auth-header".into(),
            description: "Detected a hardcoded X-SF-Authorization bearer token.".into(),
            regex: Some(
                r#"(?i)x-sf-authorization\s*[:=]\s*["']?bearer\s+([A-Za-z0-9._-]{24,})"#
                    .into(),
            ),
            path: None,
            secret_group: Some(1),
            entropy: Some(3.2),
            keywords: vec!["x-sf-authorization".into()],
            allowlist: None,
            allowlists: Vec::new(),
            severity: Some(Severity::Block),
        },
    ]
}

static SPACEFAST_SCANNER: LazyLock<Scanner> = LazyLock::new(|| {
    let config = ScanConfig::default_gitleaks().with_rules(spacefast_native_rules());
    Scanner::compile_lazy(&config).expect("Spacefast secret scanner rules must compile")
});

/// Precomputed digest over the built-in Gitleaks + Spacefast rules so
/// incremental cache checks never need to initialize `SPACEFAST_SCANNER` when
/// zero files require scanning.
static BUILTIN_SCANNER_POLICY_DIGEST: LazyLock<String> = LazyLock::new(|| {
    use sha2::{Digest, Sha256};
    let mut hasher = Sha256::new();
    hasher.update(format!("rev:{}\0", spacefast_secret_scan::SCANNER_REVISION).as_bytes());
    hasher.update(spacefast_secret_scan::DEFAULT_GITLEAKS_TOML.as_bytes());
    for (rule_id, severity) in spacefast_secret_scan::DEFAULT_RULE_TIERS {
        hasher.update(rule_id.as_bytes());
        hasher.update(b":");
        hasher.update(if *severity == Severity::Block {
            b"block\0" as &[u8]
        } else {
            b"warn\0" as &[u8]
        });
    }
    if let Ok(native) = serde_json::to_vec(&spacefast_native_rules()) {
        hasher.update(native);
    }
    format!("{:x}", hasher.finalize())
});

/// Returns the shared compiled Spacefast + Gitleaks scanner.
#[must_use]
pub fn spacefast_scanner() -> &'static Scanner {
    &SPACEFAST_SCANNER
}

/// Extracts `security.secrets.allowlist` from a parsed `sf.jsonc` or serving
/// config object (or directly from a `security` sub-object).
#[must_use]
pub fn allowlist_from_config(config: Option<&Value>) -> AllowlistOverride {
    let security = config.and_then(|c| c.get("security").or(Some(c)));
    let Some(allowlist) = security
        .and_then(|s| s.get("secrets"))
        .and_then(|s| s.get("allowlist"))
        .and_then(Value::as_object)
    else {
        return AllowlistOverride::default();
    };
    let read_strings = |key: &str| -> Vec<String> {
        allowlist
            .get(key)
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(Value::as_str)
            .map(str::trim)
            .filter(|v| !v.is_empty())
            .map(ToString::to_string)
            .collect()
    };
    let read_opt_string = |entry: &serde_json::Map<String, Value>, key: &str| -> Option<String> {
        entry
            .get(key)
            .and_then(Value::as_str)
            .map(str::trim)
            .filter(|v| !v.is_empty())
            .map(ToString::to_string)
    };
    let entries = allowlist
        .get("entries")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
        .filter_map(Value::as_object)
        .map(|entry| AllowlistEntry {
            path: read_opt_string(entry, "path"),
            rule_id: read_opt_string(entry, "ruleId"),
            fingerprint: read_opt_string(entry, "fingerprint"),
        })
        .collect();
    AllowlistOverride {
        fingerprints: read_strings("fingerprints"),
        paths: read_strings("paths"),
        rules: read_strings("rules"),
        entries,
    }
}

/// Computes the deterministic policy digest over scanner rules + allowlist
/// without compiling the regex engine.
#[must_use]
pub fn policy_digest(allowlist: &AllowlistOverride) -> String {
    stable_json_sha256(&json!({
        "wrapperRevision": SECRET_SCAN_WRAPPER_REVISION,
        "scannerPolicy": BUILTIN_SCANNER_POLICY_DIGEST.as_str(),
        "allowlist": allowlist,
    }))
}

/// Actionable, agent-friendly refusal message that never prints the raw secret.
#[must_use]
pub fn block_message(finding: &Finding) -> String {
    format!(
        "{}:{} contains a potential secret ({}, {}, fingerprint {}). Move secrets to .env.server or `sf env set`, or suppress a false positive with `gitleaks:allow` on that line or `security.secrets.allowlist.fingerprints` in sf.jsonc.",
        finding.path,
        finding.line,
        finding.rule_id,
        finding.redacted_preview,
        finding.fingerprint,
    )
}

/// Redacted structured details for a finding (shared across 422 blocks and warnings).
#[must_use]
pub fn finding_details(finding: &Finding, sha256: Option<&str>) -> Value {
    let mut details = json!({
        "path": finding.path,
        "line": finding.line,
        "column": finding.column,
        "ruleId": finding.rule_id,
        "description": finding.description,
        "severity": finding.severity,
        "redactedPreview": finding.redacted_preview,
        "fingerprint": finding.fingerprint,
        "pointer": "/files",
    });
    if let (Some(sha), Some(map)) = (sha256, details.as_object_mut()) {
        map.insert("sha256".into(), Value::String(sha.to_string()));
    }
    details
}

// ---------------------------------------------------------------------------
// WASI / pure batch scanning surface (used by CLI pre-upload check & tests)
// ---------------------------------------------------------------------------

pub const SCAN_SECRETS_INPUT_FORMAT: &str = "spacefast.finalizer.scan_secrets.input.v1";

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ScanSecretsFile {
    pub path: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub logical_path: Option<String>,
    pub content: String,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct ScanSecretsInput {
    pub format: String,
    #[serde(default)]
    pub files: Vec<ScanSecretsFile>,
    #[serde(default)]
    pub allowlist: Option<AllowlistOverride>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct ScanSecretsOutput {
    pub findings: Vec<Finding>,
    pub blocked: bool,
    pub truncated: bool,
    pub policy_digest: String,
}

/// Scans an in-memory batch of files through the shared Spacefast + Gitleaks
/// engine. Safe to call from `wasm32-wasip1`.
#[must_use]
pub fn scan_secrets_batch(input: ScanSecretsInput) -> ScanSecretsOutput {
    let scanner = spacefast_scanner();
    let allowlist = input.allowlist.unwrap_or_default();
    let override_ref = (!allowlist.is_empty()).then_some(&allowlist);
    let limits = ScanLimits::default();
    let mut findings = Vec::new();
    let mut truncated = false;
    let mut blocked = false;

    for file in input.files {
        let scan_path = file.logical_path.as_deref().unwrap_or(&file.path);
        let physical = (file.path != scan_path).then_some(file.path.as_str());
        let outcome = scanner.scan_bytes_with_paths(
            scan_path,
            physical,
            file.content.as_bytes(),
            limits,
            override_ref,
        );
        truncated |= outcome.truncated;
        for mut finding in outcome.findings {
            finding.path = file.path.clone();
            if finding.severity == Severity::Block {
                blocked = true;
            }
            findings.push(finding);
        }
    }

    ScanSecretsOutput {
        findings,
        blocked,
        truncated,
        policy_digest: policy_digest(&allowlist),
    }
}

// ---------------------------------------------------------------------------
// Native CAS-backed finalize scanner (stateful session for single-pass reads)
// ---------------------------------------------------------------------------

#[cfg(not(target_family = "wasm"))]
const INCOMPLETE_SAMPLE_LIMIT: usize = 5;

#[cfg(not(target_family = "wasm"))]
pub(crate) struct SecretScanSession<'a> {
    allowlist: AllowlistOverride,
    clean_digest: Option<String>,
    clean_cache: std::collections::HashSet<(&'a str, &'a str)>,
    visited: std::collections::HashSet<(&'a str, &'a str)>,
    seen_warning_keys: std::collections::HashSet<(String, usize, String)>,
    started: std::time::Instant,
    deadline: std::time::Duration,
    limits: ScanLimits,
    total_bytes_scanned: usize,
    warnings_emitted: usize,
    incomplete_reason: Option<&'static str>,
    incomplete_count: usize,
    incomplete_paths: Vec<String>,
    incomplete_paths_seen: std::collections::HashSet<String>,
}

#[cfg(not(target_family = "wasm"))]
impl<'a> SecretScanSession<'a> {
    pub(crate) fn new(
        previous_catalog: Option<&'a crate::catalog::FileCatalog>,
        config_for_allowlist: Option<&Value>,
    ) -> Self {
        use std::collections::HashSet;
        use std::time::{Duration, Instant};

        let allowlist = allowlist_from_config(config_for_allowlist);
        let digest = policy_digest(&allowlist);

        let mut clean_cache = HashSet::new();
        if let Some(previous) = previous_catalog
            .filter(|prev| prev.secret_scan_policy_digest.as_deref() == Some(digest.as_str()))
        {
            for (path, object) in previous.iter_objects() {
                clean_cache.insert((object.sha256.as_str(), path));
            }
        }

        Self {
            allowlist,
            clean_digest: Some(digest),
            clean_cache,
            visited: HashSet::new(),
            seen_warning_keys: HashSet::new(),
            started: Instant::now(),
            deadline: Duration::from_secs(FINALIZE_TIME_BUDGET_SECS),
            limits: ScanLimits {
                max_file_bytes: FINALIZE_MAX_FILE_BYTES,
                ..ScanLimits::default()
            },
            total_bytes_scanned: 0,
            warnings_emitted: 0,
            incomplete_reason: None,
            incomplete_count: 0,
            incomplete_paths: Vec::new(),
            incomplete_paths_seen: HashSet::new(),
        }
    }

    fn record_incomplete(&mut self, reason: &'static str, path: &str) {
        self.clean_digest = None;
        if self.incomplete_reason.is_none() {
            self.incomplete_reason = Some(reason);
        }
        if !self.incomplete_paths_seen.insert(path.to_string()) {
            return;
        }
        self.incomplete_count = self.incomplete_count.saturating_add(1);
        if self.incomplete_paths.len() < INCOMPLETE_SAMPLE_LIMIT {
            self.incomplete_paths.push(path.to_string());
        }
    }

    /// Returns `true` when `(path, sha256)` needs secret scanning (ignoring
    /// live byte/deadline budgets, which are enforced during Phase 2 reads).
    pub(crate) fn needs_scan(
        &mut self,
        path: &'a str,
        logical_path: &str,
        sha256: &'a str,
    ) -> bool {
        let key = (sha256, path);
        if !self.visited.insert(key) {
            return false;
        }
        if self.clean_cache.contains(&key) {
            return false;
        }
        !crate::decompression::is_known_binary_extension(logical_path)
    }

    /// Returns the remaining per-file byte budget right now, or `None` if the
    /// total byte budget or wall-clock deadline has been exhausted.
    pub(crate) fn current_file_cap(&mut self) -> Option<usize> {
        if self.started.elapsed() >= self.deadline {
            self.clean_digest = None;
            return None;
        }
        if self.total_bytes_scanned >= FINALIZE_MAX_TOTAL_BYTES {
            self.clean_digest = None;
            return None;
        }
        let remaining = FINALIZE_MAX_TOTAL_BYTES - self.total_bytes_scanned;
        Some(FINALIZE_MAX_FILE_BYTES.min(remaining))
    }

    pub(crate) fn record_budget_exhausted(&mut self, path: &str) {
        let reason = if self.started.elapsed() >= self.deadline {
            "time_budget_exceeded"
        } else {
            "total_byte_budget_exceeded"
        };
        self.record_incomplete(reason, path);
    }

    pub(crate) fn mark_inconclusive(&mut self, path: &str) {
        self.record_incomplete("decompression_failed", path);
    }

    /// Scans already-read/decompressed bytes in memory with zero extra disk I/O.
    /// Lazily initializes `SPACEFAST_SCANNER` on the first uncached file.
    pub(crate) fn scan_bytes(
        &mut self,
        path: &str,
        logical_path: &str,
        sha256: &str,
        bytes: &[u8],
        read_truncated: bool,
        diagnostics: &mut Vec<Value>,
    ) -> crate::finalize::Result<()> {
        use crate::finalize::invalid_with_details;

        let Some(cap) = self.current_file_cap() else {
            self.record_budget_exhausted(path);
            return Ok(());
        };
        let slice_len = bytes.len().min(cap);
        let budget_truncated = bytes.len() > cap;
        let scanner = spacefast_scanner();
        let override_ref = (!self.allowlist.is_empty()).then_some(&self.allowlist);
        let physical = (path != logical_path).then_some(path);
        let outcome = scanner.scan_bytes_with_paths(
            logical_path,
            physical,
            &bytes[..slice_len],
            self.limits,
            override_ref,
        );
        self.total_bytes_scanned = self
            .total_bytes_scanned
            .saturating_add(outcome.bytes_scanned);
        if read_truncated || budget_truncated {
            self.record_incomplete("file_byte_limit_exceeded", path);
        } else if outcome.truncated {
            self.record_incomplete("finding_cap_exceeded", path);
        }

        for mut finding in outcome.findings {
            // Preserve the actual committed path (e.g. `app.js.gz`) in reports.
            finding.path = path.to_string();
            match finding.severity {
                Severity::Block => {
                    return invalid_with_details(
                        "secret_detected",
                        block_message(&finding),
                        finding_details(&finding, Some(sha256)),
                    );
                }
                Severity::Warn => {
                    if !self.seen_warning_keys.insert((
                        finding.path.clone(),
                        finding.line,
                        finding.fingerprint.clone(),
                    )) {
                        continue;
                    }
                    self.clean_digest = None;
                    if self.warnings_emitted < FINALIZE_MAX_WARNINGS {
                        self.warnings_emitted += 1;
                        let fix = format!(
                            "Verify this value is intended to be public. Move secrets to .env.server or `sf env set`, or suppress a false positive with `gitleaks:allow` on line {} or fingerprint \"{}\" in `sf.jsonc` under `security.secrets.allowlist.fingerprints`.",
                            finding.line, finding.fingerprint
                        );
                        let mut details = finding_details(&finding, Some(sha256));
                        if let Some(map) = details.as_object_mut() {
                            map.insert(
                                "category".into(),
                                Value::String(
                                    crate::hygiene::DiagnosticCategory::Security.as_str().into(),
                                ),
                            );
                            map.insert("fix".into(), Value::String(fix.clone()));
                        }
                        diagnostics.push(json!({
                            "severity": "warning",
                            "code": "secret_detected_warning",
                            "message": format!(
                                "{}:{} matches secret pattern {} ({}, fingerprint {}). Verify this value is intended to be public, or suppress with `gitleaks:allow`.",
                                finding.path,
                                finding.line,
                                finding.rule_id,
                                finding.redacted_preview,
                                finding.fingerprint,
                            ),
                            "path": format!("{}:{}", finding.path, finding.line),
                            "fix": fix,
                            "details": details,
                        }));
                    }
                }
            }
        }
        Ok(())
    }

    pub(crate) fn finish(self, diagnostics: &mut Vec<Value>) -> Option<String> {
        if let Some(reason) = self.incomplete_reason {
            let fix = "Review large or skipped files for accidental secrets, or split oversized bundles so secret scanning inspects the entire version.";
            let sample = self.incomplete_paths.join(", ");
            let message = if sample.is_empty() {
                format!(
                    "Secret scanning did not cover all files in this version ({reason}; {} file(s) skipped or truncated).",
                    self.incomplete_count
                )
            } else {
                format!(
                    "Secret scanning did not cover all files in this version ({reason}; {} file(s) skipped or truncated, including {sample}).",
                    self.incomplete_count
                )
            };
            let first_path = self.incomplete_paths.first().cloned();
            let mut diag = json!({
                "severity": "warning",
                "code": "secret_scan_incomplete",
                "message": message,
                "fix": fix,
                "details": {
                    "category": crate::hygiene::DiagnosticCategory::Security.as_str(),
                    "reason": reason,
                    "skippedOrTruncatedCount": self.incomplete_count,
                    "samplePaths": self.incomplete_paths,
                    "fix": fix,
                },
            });
            if let (Some(path), Some(obj)) = (first_path, diag.as_object_mut()) {
                obj.insert("path".into(), Value::String(path));
            }
            diagnostics.push(diag);
        }
        self.clean_digest
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn spacefast_native_tokens_block_and_never_echo_raw_secret() {
        let token = format!("{}{}", "sfa_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8u");
        let claim = format!(
            "https://my.spacefast.com/claim/{}",
            "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8u"
        );
        let out = scan_secrets_batch(ScanSecretsInput {
            format: SCAN_SECRETS_INPUT_FORMAT.into(),
            files: vec![ScanSecretsFile {
                path: "index.html".into(),
                logical_path: None,
                content: format!("<script>const t = '{token}'; const c = '{claim}';</script>"),
            }],
            allowlist: None,
        });
        assert!(out.blocked);
        assert!(out
            .findings
            .iter()
            .any(|f| f.rule_id == "spacefast-api-token" && f.severity == Severity::Block));
        assert!(out
            .findings
            .iter()
            .any(|f| f.rule_id == "spacefast-claim-url" && f.severity == Severity::Block));
        for finding in &out.findings {
            assert!(!finding.redacted_preview.contains(&token));
            assert!(!block_message(finding).contains(&token));
        }

        // logical_path governs path allowlists while diagnostics retain the uploaded path.
        let gz_blocked = scan_secrets_batch(ScanSecretsInput {
            format: SCAN_SECRETS_INPUT_FORMAT.into(),
            files: vec![ScanSecretsFile {
                path: "docs/example.js.gz".into(),
                logical_path: Some("docs/example.js".into()),
                content: format!("const t = '{token}';"),
            }],
            allowlist: None,
        });
        assert!(gz_blocked.blocked);
        assert_eq!(gz_blocked.findings[0].path, "docs/example.js.gz");

        let gz_allowed = scan_secrets_batch(ScanSecretsInput {
            format: SCAN_SECRETS_INPUT_FORMAT.into(),
            files: vec![ScanSecretsFile {
                path: "docs/example.js.gz".into(),
                logical_path: Some("docs/example.js".into()),
                content: format!("const t = '{token}';"),
            }],
            allowlist: Some(AllowlistOverride {
                paths: vec!["docs/example.js".into()],
                ..Default::default()
            }),
        });
        assert!(!gz_allowed.blocked);
        assert!(gz_allowed.findings.is_empty());

        // Allowlisting the reported physical path (`docs/example.js.gz`) also works identically.
        let gz_physical_allowed = scan_secrets_batch(ScanSecretsInput {
            format: SCAN_SECRETS_INPUT_FORMAT.into(),
            files: vec![ScanSecretsFile {
                path: "docs/example.js.gz".into(),
                logical_path: Some("docs/example.js".into()),
                content: format!("const t = '{token}';"),
            }],
            allowlist: Some(AllowlistOverride {
                paths: vec!["docs/example.js.gz".into()],
                ..Default::default()
            }),
        });
        assert!(!gz_physical_allowed.blocked);
        assert!(gz_physical_allowed.findings.is_empty());

        // Scoped physical-path allowlist exceptions must be applied BEFORE the
        // per-file Block cap so 35 allowlisted hits cannot hide an unrelated Block secret.
        let mut many_allowlisted = String::new();
        for i in 0..35 {
            let sfa = format!("sfa_9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS{i:02}");
            many_allowlisted.push_str(&format!("const t{i} = '{sfa}';\n"));
        }
        let ghp = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        many_allowlisted.push_str(&format!("const gh = '{ghp}';\n"));

        let scoped_allowlist = AllowlistOverride {
            entries: vec![AllowlistEntry {
                path: Some("docs/bundle.js.gz".into()),
                rule_id: Some("spacefast-api-token".into()),
                fingerprint: None,
            }],
            ..Default::default()
        };
        let batch_out = scan_secrets_batch(ScanSecretsInput {
            format: SCAN_SECRETS_INPUT_FORMAT.into(),
            files: vec![ScanSecretsFile {
                path: "docs/bundle.js.gz".into(),
                logical_path: Some("docs/bundle.js".into()),
                content: many_allowlisted.clone(),
            }],
            allowlist: Some(scoped_allowlist),
        });
        assert!(batch_out.blocked);
        assert!(batch_out
            .findings
            .iter()
            .any(|f| f.rule_id == "github-pat" && f.path == "docs/bundle.js.gz"));
    }

    #[cfg(not(target_family = "wasm"))]
    #[test]
    fn emits_secret_scan_incomplete_warning_and_applies_physical_allowlists_before_cap() {
        let mut session = SecretScanSession::new(None, None);
        let mut diagnostics = Vec::new();
        session
            .scan_bytes(
                "huge.js",
                "huge.js",
                "abc",
                b"export const ok = 1;\n",
                true,
                &mut diagnostics,
            )
            .expect("truncated clean file does not block");
        // Recording the same path again (e.g. planning + scan_bytes) must not double-count.
        session.record_budget_exhausted("huge.js");
        let digest = session.finish(&mut diagnostics);
        assert!(digest.is_none());
        let incomplete = diagnostics
            .iter()
            .find(|d| d["code"] == "secret_scan_incomplete")
            .expect("must emit secret_scan_incomplete warning");
        assert_eq!(incomplete["severity"], "warning");
        assert_eq!(incomplete["details"]["reason"], "file_byte_limit_exceeded");
        assert_eq!(incomplete["details"]["skippedOrTruncatedCount"], 1);
        assert!(incomplete["fix"].as_str().is_some());
        assert!(incomplete["details"]["fix"].as_str().is_some());

        // Native session: 35 allowlisted physical-path hits must not blind an unrelated Block hit.
        let mut many_allowlisted = String::new();
        for i in 0..35 {
            let sfa = format!("sfa_9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS{i:02}");
            many_allowlisted.push_str(&format!("const t{i} = '{sfa}';\n"));
        }
        let ghp = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        many_allowlisted.push_str(&format!("const gh = '{ghp}';\n"));
        let config = json!({
            "secrets": {
                "allowlist": {
                    "entries": [{
                        "path": "docs/bundle.js.gz",
                        "ruleId": "spacefast-api-token"
                    }]
                }
            }
        });
        let mut native_session = SecretScanSession::new(None, Some(&config));
        let mut native_diags = Vec::new();
        let err = native_session
            .scan_bytes(
                "docs/bundle.js.gz",
                "docs/bundle.js",
                "deadbeef",
                many_allowlisted.as_bytes(),
                false,
                &mut native_diags,
            )
            .expect_err(
                "unrelated github-pat must still block after 35 physical-path allowlisted hits",
            );
        match err {
            crate::finalize::FinalizeError::Invalid { code, details, .. } => {
                assert_eq!(code, "secret_detected");
                assert_eq!(
                    details
                        .as_ref()
                        .and_then(|d| d.get("ruleId"))
                        .and_then(Value::as_str),
                    Some("github-pat")
                );
            }
            other => panic!("unexpected error: {other:?}"),
        }
    }
}
