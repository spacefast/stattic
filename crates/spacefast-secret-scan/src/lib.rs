//! Linear-time Rust secret scanner consuming `gitleaks.toml` rules.
//!
//! Designed for both native servers and `wasm32-wasip1` sandboxes: no
//! filesystem, process, or network access is required. Every [`Finding`] emits
//! only a redacted preview and a deterministic fingerprint — never the raw
//! secret bytes.

pub mod config;
pub mod tiers;

use aho_corasick::{AhoCorasick, AhoCorasickBuilder, MatchKind};
use regex::{Regex, RegexBuilder, RegexSet, RegexSetBuilder};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::borrow::Cow;
use std::collections::{BTreeMap, BTreeSet};
use std::fmt;
use std::io::Read;
use std::sync::{LazyLock, OnceLock};

pub use config::{AllowlistCondition, AllowlistConfig, RegexTarget, RuleConfig, ScanConfig};
pub use tiers::{default_rule_tiers, default_tier_for, Severity, DEFAULT_RULE_TIERS};

/// Bump whenever scanning semantics change so downstream caches invalidate.
pub const SCANNER_REVISION: u32 = 1;

/// Embedded upstream `gitleaks.toml` (MIT licensed, pinned in `vendor/UPSTREAM.json`).
#[cfg(feature = "default-rules")]
pub const DEFAULT_GITLEAKS_TOML: &str = include_str!("../vendor/gitleaks.toml");

/// Bounds applied to a single scanned file.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ScanLimits {
    /// Maximum UTF-8/text bytes inspected per file. Bytes beyond this cap are
    /// not read and mark [`ScanOutcome::truncated`].
    pub max_file_bytes: usize,
    /// Chunk window size used when scanning large or single-line minified files.
    pub chunk_bytes: usize,
    /// Overlap preserved between consecutive chunks so multi-line secrets
    /// (such as PEM blocks) straddling a boundary still match.
    pub chunk_overlap_bytes: usize,
    /// Maximum findings recorded for a single file.
    pub max_findings_per_file: usize,
}

impl Default for ScanLimits {
    fn default() -> Self {
        Self {
            max_file_bytes: 4 * 1024 * 1024,
            chunk_bytes: 64 * 1024,
            chunk_overlap_bytes: 4 * 1024,
            max_findings_per_file: 32,
        }
    }
}

/// Structured caller allowlist (for example, parsed from a project config).
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AllowlistOverride {
    #[serde(default)]
    pub fingerprints: Vec<String>,
    #[serde(default)]
    pub paths: Vec<String>,
    #[serde(default)]
    pub rules: Vec<String>,
    #[serde(default)]
    pub entries: Vec<AllowlistEntry>,
}

/// One scoped allowlist entry; all present fields must match (logical AND).
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AllowlistEntry {
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub path: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub rule_id: Option<String>,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub fingerprint: Option<String>,
}

impl AllowlistOverride {
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.fingerprints.is_empty()
            && self.paths.is_empty()
            && self.rules.is_empty()
            && self.entries.is_empty()
    }

    #[must_use]
    pub fn matches(&self, path: &str, rule_id: &str, fingerprint: &str) -> bool {
        if self
            .fingerprints
            .iter()
            .any(|candidate| candidate.eq_ignore_ascii_case(fingerprint))
        {
            return true;
        }
        if self.rules.iter().any(|candidate| candidate == rule_id) {
            return true;
        }
        if self.paths.iter().any(|pattern| path_matches(pattern, path)) {
            return true;
        }
        self.entries.iter().any(|entry| {
            let has_any =
                entry.path.is_some() || entry.rule_id.is_some() || entry.fingerprint.is_some();
            if !has_any {
                return false;
            }
            entry
                .path
                .as_deref()
                .is_none_or(|pattern| path_matches(pattern, path))
                && entry
                    .rule_id
                    .as_deref()
                    .is_none_or(|candidate| candidate == rule_id)
                && entry
                    .fingerprint
                    .as_deref()
                    .is_none_or(|candidate| candidate.eq_ignore_ascii_case(fingerprint))
        })
    }
}

/// Redacted secret detection finding. Raw secret text is intentionally absent.
#[derive(Debug, Clone, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Finding {
    pub rule_id: String,
    pub description: String,
    pub path: String,
    pub line: usize,
    pub column: usize,
    pub severity: Severity,
    pub redacted_preview: String,
    pub fingerprint: String,
    pub entropy: f64,
}

/// Result of scanning one file.
#[derive(Debug, Clone, Default, PartialEq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct ScanOutcome {
    pub findings: Vec<Finding>,
    pub truncated: bool,
    pub skipped_binary: bool,
    pub bytes_scanned: usize,
}

/// Error returned while compiling a [`ScanConfig`] into a [`Scanner`].
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CompileError {
    pub context: String,
    pub message: String,
}

impl fmt::Display for CompileError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "{}: {}", self.context, self.message)
    }
}

impl std::error::Error for CompileError {}

/// Compiled regex that can either be built eagerly (`Scanner::compile`) or
/// lazily on first match (`Scanner::compile_lazy`) to keep cold-start latency
/// under a few milliseconds on subprocess and WASI invocations.
enum LazyRegex {
    Eager(Regex),
    Lazy {
        pattern: String,
        compiled: OnceLock<Option<Regex>>,
    },
}

impl LazyRegex {
    fn eager(context: &str, pattern: &str) -> Result<Self, CompileError> {
        Ok(Self::Eager(compile_regex(context, pattern)?))
    }

    fn lazy(pattern: &str) -> Self {
        Self::Lazy {
            pattern: pattern.to_string(),
            compiled: OnceLock::new(),
        }
    }

    fn get(&self) -> Option<&Regex> {
        match self {
            Self::Eager(re) => Some(re),
            Self::Lazy { pattern, compiled } => compiled
                .get_or_init(|| compile_regex("lazy", pattern).ok())
                .as_ref(),
        }
    }
}

enum LazyRegexSet {
    Eager(RegexSet),
    Lazy {
        patterns: Vec<String>,
        compiled: OnceLock<Option<RegexSet>>,
    },
}

impl LazyRegexSet {
    fn eager(context: &str, patterns: &[String]) -> Result<Self, CompileError> {
        Ok(Self::Eager(compile_regex_set(context, patterns)?))
    }

    fn lazy(patterns: &[String]) -> Self {
        Self::Lazy {
            patterns: patterns.to_vec(),
            compiled: OnceLock::new(),
        }
    }

    fn is_match(&self, text: &str) -> bool {
        match self {
            Self::Eager(set) => set.is_match(text),
            Self::Lazy { patterns, compiled } => compiled
                .get_or_init(|| compile_regex_set("lazy", patterns).ok())
                .as_ref()
                .is_some_and(|set| set.is_match(text)),
        }
    }
}

struct CompiledAllowlist {
    condition: AllowlistCondition,
    regex_target: RegexTarget,
    paths: Option<LazyRegexSet>,
    regexes: Option<LazyRegexSet>,
    stopwords: Vec<String>,
}

impl CompiledAllowlist {
    fn compile(context: &str, raw: &AllowlistConfig, lazy: bool) -> Result<Self, CompileError> {
        let paths = if raw.paths.is_empty() {
            None
        } else if lazy {
            Some(LazyRegexSet::lazy(&raw.paths))
        } else {
            Some(LazyRegexSet::eager(
                &format!("{context}.paths"),
                &raw.paths,
            )?)
        };
        let regexes = if raw.regexes.is_empty() {
            None
        } else if lazy {
            Some(LazyRegexSet::lazy(&raw.regexes))
        } else {
            Some(LazyRegexSet::eager(
                &format!("{context}.regexes"),
                &raw.regexes,
            )?)
        };
        let stopwords = raw
            .stopwords
            .iter()
            .filter(|word| !word.is_empty())
            .map(|word| word.to_ascii_lowercase())
            .collect();
        Ok(Self {
            condition: raw.condition,
            regex_target: raw.regex_target,
            paths,
            regexes,
            stopwords,
        })
    }

    fn matches_path_only(&self, path: &str) -> bool {
        let Some(paths) = &self.paths else {
            return false;
        };
        // Only short-circuit a whole file when the allowlist is OR-combined
        // (or has no other conditions) and a path regex matches.
        if self.condition == AllowlistCondition::And
            && (self.regexes.is_some() || !self.stopwords.is_empty())
        {
            return false;
        }
        paths.is_match(path)
    }

    fn matches(&self, path: &str, secret: &str, full_match: &str, line: &str) -> bool {
        let mut evaluated_any = false;
        if let Some(paths) = &self.paths {
            evaluated_any = true;
            let hit = paths.is_match(path);
            match self.condition {
                AllowlistCondition::Or if hit => return true,
                AllowlistCondition::And if !hit => return false,
                _ => {}
            }
        }
        if let Some(regexes) = &self.regexes {
            evaluated_any = true;
            let target = match self.regex_target {
                RegexTarget::Secret => secret,
                RegexTarget::Match => full_match,
                RegexTarget::Line => line,
            };
            let hit = regexes.is_match(target);
            match self.condition {
                AllowlistCondition::Or if hit => return true,
                AllowlistCondition::And if !hit => return false,
                _ => {}
            }
        }
        if !self.stopwords.is_empty() {
            evaluated_any = true;
            let lower = secret.to_ascii_lowercase();
            let hit = self.stopwords.iter().any(|word| lower.contains(word));
            match self.condition {
                AllowlistCondition::Or if hit => return true,
                AllowlistCondition::And if !hit => return false,
                _ => {}
            }
        }
        if !evaluated_any {
            return false;
        }
        matches!(self.condition, AllowlistCondition::And)
    }
}

fn compile_allowlists(
    prefix: &str,
    single: Option<&AllowlistConfig>,
    list: &[AllowlistConfig],
    lazy: bool,
) -> Result<Vec<CompiledAllowlist>, CompileError> {
    let mut out = Vec::with_capacity(usize::from(single.is_some()) + list.len());
    if let Some(allowlist) = single {
        out.push(CompiledAllowlist::compile(
            &format!("{prefix}allowlist"),
            allowlist,
            lazy,
        )?);
    }
    for (idx, allowlist) in list.iter().enumerate() {
        out.push(CompiledAllowlist::compile(
            &format!("{prefix}allowlists[{idx}]"),
            allowlist,
            lazy,
        )?);
    }
    Ok(out)
}

struct CompiledRule {
    id: String,
    description: String,
    regex: Option<LazyRegex>,
    path: Option<LazyRegex>,
    secret_group: Option<usize>,
    entropy: Option<f64>,
    allowlists: Vec<CompiledAllowlist>,
    severity: Severity,
}

/// Compiled, reusable secret scanner. Safe to share across threads (`Send + Sync`).
pub struct Scanner {
    rules: Vec<CompiledRule>,
    global_allowlists: Vec<CompiledAllowlist>,
    keyword_matcher: Option<AhoCorasick>,
    keyword_to_rules: Vec<Vec<usize>>,
    keywordless_rules: Vec<usize>,
    path_only_rules: Vec<usize>,
    policy_digest: String,
}

static DATA_URI_BASE64: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)data:[a-z0-9+./-]+(?:;[a-z0-9=.-]+)*;base64,[A-Za-z0-9+/=_-]{32,}")
        .expect("static data URI regex")
});

impl Scanner {
    /// Compiles a [`ScanConfig`] eagerly, validating every regex up front.
    pub fn compile(config: &ScanConfig) -> Result<Self, CompileError> {
        Self::compile_with_mode(config, false)
    }

    /// Compiles the Aho-Corasick prefilter eagerly and defers per-rule regex
    /// compilation until first keyword hit. Ideal for short-lived subprocesses
    /// and WASI invocations using pre-validated built-in rule sets.
    pub fn compile_lazy(config: &ScanConfig) -> Result<Self, CompileError> {
        Self::compile_with_mode(config, true)
    }

    fn compile_with_mode(config: &ScanConfig, lazy: bool) -> Result<Self, CompileError> {
        let disabled: BTreeSet<&str> = config.disabled_rules.iter().map(String::as_str).collect();
        let global_allowlists =
            compile_allowlists("", config.allowlist.as_ref(), &config.allowlists, lazy)?;

        let mut staged_rules: Vec<(CompiledRule, Vec<String>)> =
            Vec::with_capacity(config.rules.len());

        for raw in &config.rules {
            if disabled.contains(raw.id.as_str()) {
                continue;
            }
            let regex = raw
                .regex
                .as_deref()
                .map(|pat| {
                    if lazy {
                        Ok(LazyRegex::lazy(pat))
                    } else {
                        LazyRegex::eager(&format!("rules.{}.regex", raw.id), pat)
                    }
                })
                .transpose()?;
            let path = raw
                .path
                .as_deref()
                .map(|pat| {
                    if lazy {
                        Ok(LazyRegex::lazy(pat))
                    } else {
                        LazyRegex::eager(&format!("rules.{}.path", raw.id), pat)
                    }
                })
                .transpose()?;

            let allowlists = compile_allowlists(
                &format!("rules.{}.", raw.id),
                raw.allowlist.as_ref(),
                &raw.allowlists,
                lazy,
            )?;

            let severity = raw
                .severity
                .or_else(|| config.tiers.get(&raw.id).copied())
                .or_else(|| default_tier_for(&raw.id))
                .unwrap_or(Severity::Warn);

            staged_rules.push((
                CompiledRule {
                    id: raw.id.clone(),
                    description: raw.description.clone(),
                    regex,
                    path,
                    secret_group: raw.secret_group,
                    entropy: raw.entropy,
                    allowlists,
                    severity,
                },
                raw.keywords.clone(),
            ));
        }

        // Always evaluate Tier-1 Block rules before Tier-2 Warn rules so a noisy
        // file hitting `max_findings_per_file` on warnings can never blind a
        // blocking secret rule.
        staged_rules.sort_by_key(|(rule, _)| match rule.severity {
            Severity::Block => 0u8,
            Severity::Warn => 1u8,
        });

        let mut rules = Vec::with_capacity(staged_rules.len());
        let mut keyword_map: BTreeMap<String, Vec<usize>> = BTreeMap::new();
        let mut keywordless_rules = Vec::new();
        let mut path_only_rules = Vec::new();

        for (rule, keywords) in staged_rules {
            let rule_index = rules.len();
            if rule.regex.is_none() {
                if rule.path.is_some() {
                    path_only_rules.push(rule_index);
                }
            } else if keywords.is_empty() {
                keywordless_rules.push(rule_index);
            } else {
                for keyword in &keywords {
                    let lower = keyword.to_ascii_lowercase();
                    if lower.is_empty() {
                        continue;
                    }
                    let entry = keyword_map.entry(lower).or_default();
                    if entry.last().copied() != Some(rule_index) {
                        entry.push(rule_index);
                    }
                }
            }
            rules.push(rule);
        }

        let mut patterns = Vec::with_capacity(keyword_map.len());
        let mut keyword_to_rules = Vec::with_capacity(keyword_map.len());
        for (keyword, rule_indices) in keyword_map {
            patterns.push(keyword);
            keyword_to_rules.push(rule_indices);
        }

        let keyword_matcher = if patterns.is_empty() {
            None
        } else {
            Some(
                AhoCorasickBuilder::new()
                    .ascii_case_insensitive(true)
                    .match_kind(MatchKind::Standard)
                    .build(&patterns)
                    .map_err(|error| CompileError {
                        context: "keywords".into(),
                        message: error.to_string(),
                    })?,
            )
        };

        let policy_digest = compute_policy_digest(config);
        Ok(Self {
            rules,
            global_allowlists,
            keyword_matcher,
            keyword_to_rules,
            keywordless_rules,
            path_only_rules,
            policy_digest,
        })
    }

    /// Compiles the default embedded `gitleaks.toml` rule set lazily (fast startup).
    #[cfg(feature = "default-rules")]
    #[must_use]
    pub fn default_gitleaks() -> Self {
        Self::compile_lazy(&ScanConfig::default_gitleaks())
            .expect("vendored gitleaks.toml rules must compile")
    }

    /// Number of active compiled rules.
    #[must_use]
    pub fn rule_count(&self) -> usize {
        self.rules.len()
    }

    /// Deterministic SHA-256 digest over scanner revision, rules, tiers, and
    /// allowlists. Suitable for incremental cache keys.
    #[must_use]
    pub fn policy_digest(&self) -> &str {
        &self.policy_digest
    }

    /// Scans a bounded prefix read from `reader`.
    pub fn scan_reader<R: Read>(
        &self,
        path: &str,
        reader: R,
        limits: ScanLimits,
        overrides: Option<&AllowlistOverride>,
    ) -> std::io::Result<ScanOutcome> {
        let cap = limits.max_file_bytes.saturating_add(1);
        let mut buffer = Vec::new();
        reader.take(cap as u64).read_to_end(&mut buffer)?;
        let truncated_read = buffer.len() > limits.max_file_bytes;
        if truncated_read {
            buffer.truncate(limits.max_file_bytes);
        }
        let mut outcome = self.scan_bytes(path, &buffer, limits, overrides);
        outcome.truncated |= truncated_read;
        Ok(outcome)
    }

    /// Scans raw bytes for a single file path.
    #[must_use]
    pub fn scan_bytes(
        &self,
        path: &str,
        bytes: &[u8],
        limits: ScanLimits,
        overrides: Option<&AllowlistOverride>,
    ) -> ScanOutcome {
        self.scan_bytes_with_paths(path, None, bytes, limits, overrides)
    }

    /// Scans raw bytes using `path` for rule matching and fingerprinting while
    /// evaluating `overrides` against both `path` and an optional `physical_path`
    /// (for example, `app.js` and `app.js.gz`) before counting findings toward
    /// `limits.max_findings_per_file`.
    #[must_use]
    pub fn scan_bytes_with_paths(
        &self,
        path: &str,
        physical_path: Option<&str>,
        bytes: &[u8],
        limits: ScanLimits,
        overrides: Option<&AllowlistOverride>,
    ) -> ScanOutcome {
        let mut outcome = ScanOutcome::default();
        let physical_path = physical_path.filter(|phys| *phys != path);

        // Path-only rules (e.g. `pkcs12-file`) apply even to binary files.
        for &rule_index in &self.path_only_rules {
            let rule = &self.rules[rule_index];
            let Some(path_re) = rule.path.as_ref().and_then(LazyRegex::get) else {
                continue;
            };
            if !path_re.is_match(path) {
                continue;
            }
            if self
                .global_allowlists
                .iter()
                .any(|al| al.matches(path, path, path, path))
                || rule
                    .allowlists
                    .iter()
                    .any(|al| al.matches(path, path, path, path))
            {
                continue;
            }
            let fingerprint = secret_fingerprint(&rule.id, path);
            if overrides.is_some_and(|ov| {
                ov.matches(path, &rule.id, &fingerprint)
                    || physical_path.is_some_and(|phys| ov.matches(phys, &rule.id, &fingerprint))
            }) {
                continue;
            }
            outcome.findings.push(Finding {
                rule_id: rule.id.clone(),
                description: rule.description.clone(),
                path: path.to_string(),
                line: 1,
                column: 1,
                severity: rule.severity,
                redacted_preview: redact_secret(path),
                fingerprint,
                entropy: shannon_entropy(path),
            });
        }

        if self
            .global_allowlists
            .iter()
            .any(|al| al.matches_path_only(path))
        {
            return outcome;
        }

        let inspect_len = bytes.len().min(limits.max_file_bytes);
        let slice = &bytes[..inspect_len];

        if is_likely_binary(slice) {
            outcome.bytes_scanned = slice.len().min(8192);
            outcome.skipped_binary = true;
            return outcome;
        }

        if bytes.len() > limits.max_file_bytes {
            outcome.truncated = true;
        }
        outcome.bytes_scanned = slice.len();

        let raw_text = String::from_utf8_lossy(slice);
        // Mask inline `data:...;base64,...` URIs with same-length spaces so
        // line/column coordinates stay exact while embedded fonts/images never
        // trigger regex work or false positives.
        let text = mask_base64_data_uris(&raw_text);

        let chunk_bytes = limits.chunk_bytes.max(4096);
        let overlap_bytes = limits.chunk_overlap_bytes.min(chunk_bytes / 2);
        let mut ctx = FileScanCtx {
            path,
            physical_path,
            full_text: &text,
            line_starts: None,
            max_findings: limits.max_findings_per_file,
            overrides,
            seen: BTreeSet::new(),
            block_findings: Vec::new(),
            warn_findings: Vec::new(),
            block_capped: false,
            warn_capped: false,
            candidate_bits: vec![0u64; self.rules.len().div_ceil(64)],
        };

        let mut offset = 0usize;
        while offset < text.len() {
            if ctx.block_capped && ctx.warn_capped {
                break;
            }
            let raw_end = (offset + chunk_bytes).min(text.len());
            let end = floor_char_boundary(&text, raw_end);
            let chunk = &text[offset..end];

            ctx.scan_chunk(self, chunk, offset);
            if ctx.block_capped && ctx.warn_capped {
                break;
            }

            if end >= text.len() {
                break;
            }
            let next_start = ceil_char_boundary(&text, end.saturating_sub(overlap_bytes));
            offset = if next_start <= offset {
                end
            } else {
                next_start
            };
        }

        let remaining_for_warns = limits
            .max_findings_per_file
            .saturating_sub(ctx.block_findings.len());
        let dropped_warns = ctx.warn_findings.len() > remaining_for_warns;
        if ctx.block_capped || ctx.warn_capped || dropped_warns {
            outcome.truncated = true;
        }
        outcome.findings.extend(ctx.block_findings);
        outcome
            .findings
            .extend(ctx.warn_findings.into_iter().take(remaining_for_warns));

        outcome.findings.sort_by(|left, right| {
            left.line
                .cmp(&right.line)
                .then_with(|| left.column.cmp(&right.column))
                .then_with(|| left.rule_id.cmp(&right.rule_id))
        });
        outcome
    }
}

struct FileScanCtx<'a> {
    path: &'a str,
    physical_path: Option<&'a str>,
    full_text: &'a str,
    line_starts: Option<Vec<usize>>,
    max_findings: usize,
    overrides: Option<&'a AllowlistOverride>,
    seen: BTreeSet<(String, usize, String)>,
    block_findings: Vec<Finding>,
    warn_findings: Vec<Finding>,
    block_capped: bool,
    warn_capped: bool,
    candidate_bits: Vec<u64>,
}

impl FileScanCtx<'_> {
    fn scan_chunk(&mut self, scanner: &Scanner, chunk: &str, chunk_offset: usize) {
        self.candidate_bits.fill(0);
        for &rule_index in &scanner.keywordless_rules {
            self.candidate_bits[rule_index / 64] |= 1u64 << (rule_index % 64);
        }
        if let Some(matcher) = &scanner.keyword_matcher {
            for hit in matcher.find_overlapping_iter(chunk) {
                for &rule_index in &scanner.keyword_to_rules[hit.pattern().as_usize()] {
                    self.candidate_bits[rule_index / 64] |= 1u64 << (rule_index % 64);
                }
            }
        }

        for word_idx in 0..self.candidate_bits.len() {
            let mut word = self.candidate_bits[word_idx];
            while word != 0 {
                let bit = word.trailing_zeros() as usize;
                word &= word - 1;
                let rule_index = word_idx * 64 + bit;
                let rule = &scanner.rules[rule_index];
                match rule.severity {
                    Severity::Block if self.block_findings.len() >= self.max_findings => {
                        self.block_capped = true;
                        continue;
                    }
                    Severity::Warn if self.warn_findings.len() >= self.max_findings => {
                        self.warn_capped = true;
                        // All Block rules are ordered ahead of Warn rules, so no
                        // remaining rule in this chunk can be Severity::Block.
                        return;
                    }
                    _ => {}
                }
                if let Some(path_re) = rule.path.as_ref().and_then(LazyRegex::get) {
                    if !path_re.is_match(self.path) {
                        continue;
                    }
                }
                let Some(regex) = rule.regex.as_ref().and_then(LazyRegex::get) else {
                    continue;
                };

                for captures in regex.captures_iter(chunk) {
                    match rule.severity {
                        Severity::Block if self.block_findings.len() >= self.max_findings => {
                            self.block_capped = true;
                            break;
                        }
                        Severity::Warn if self.warn_findings.len() >= self.max_findings => {
                            self.warn_capped = true;
                            return;
                        }
                        _ => {}
                    }
                    let Some(full_match) = captures.get(0) else {
                        continue;
                    };
                    let secret_match =
                        extract_secret_capture(&captures, rule.secret_group).unwrap_or(full_match);
                    let secret_text = secret_match.as_str();
                    if secret_text.is_empty() {
                        continue;
                    }

                    let entropy = shannon_entropy(secret_text);
                    if let Some(min_entropy) = rule.entropy {
                        if entropy < min_entropy {
                            continue;
                        }
                    }

                    let abs_start = chunk_offset + secret_match.start();
                    let abs_end = chunk_offset + secret_match.end();
                    let line_starts = self
                        .line_starts
                        .get_or_insert_with(|| build_line_index(self.full_text));
                    let (line, column, line_slice) =
                        line_context(self.full_text, line_starts, abs_start, abs_end);

                    if line_has_inline_allow(line_slice) {
                        continue;
                    }

                    let match_text = full_match.as_str();
                    if scanner
                        .global_allowlists
                        .iter()
                        .any(|al| al.matches(self.path, secret_text, match_text, line_slice))
                    {
                        continue;
                    }
                    if rule
                        .allowlists
                        .iter()
                        .any(|al| al.matches(self.path, secret_text, match_text, line_slice))
                    {
                        continue;
                    }

                    let fingerprint = secret_fingerprint(&rule.id, secret_text);
                    if self.overrides.is_some_and(|ov| {
                        ov.matches(self.path, &rule.id, &fingerprint)
                            || self
                                .physical_path
                                .is_some_and(|phys| ov.matches(phys, &rule.id, &fingerprint))
                    }) {
                        continue;
                    }

                    if !self
                        .seen
                        .insert((rule.id.clone(), line, fingerprint.clone()))
                    {
                        continue;
                    }

                    let finding = Finding {
                        rule_id: rule.id.clone(),
                        description: rule.description.clone(),
                        path: self.path.to_string(),
                        line,
                        column,
                        severity: rule.severity,
                        redacted_preview: redact_secret(secret_text),
                        fingerprint,
                        entropy: (entropy * 100.0).round() / 100.0,
                    };
                    match rule.severity {
                        Severity::Block => self.block_findings.push(finding),
                        Severity::Warn => self.warn_findings.push(finding),
                    }
                }
            }
        }
    }
}

fn compile_regex_set(context: &str, patterns: &[String]) -> Result<RegexSet, CompileError> {
    let normalized: Vec<String> = patterns.iter().map(|p| normalize_re2_pattern(p)).collect();
    RegexSetBuilder::new(&normalized)
        .unicode(false)
        .size_limit(16 * 1024 * 1024)
        .build()
        .or_else(|_| {
            RegexSetBuilder::new(&normalized)
                .size_limit(16 * 1024 * 1024)
                .build()
        })
        .map_err(|error| CompileError {
            context: context.to_string(),
            message: error.to_string(),
        })
}

fn compile_regex(context: &str, pattern: &str) -> Result<Regex, CompileError> {
    let normalized = normalize_re2_pattern(pattern);
    // Go's RE2 `regexp` treats `\w`, `\d`, `\s`, and `\b` as ASCII character
    // classes; disabling Unicode mode matches Go semantics and keeps all 222
    // Gitleaks rules tiny and millisecond-fast to compile.
    RegexBuilder::new(&normalized)
        .unicode(false)
        .size_limit(16 * 1024 * 1024)
        .build()
        .or_else(|_| {
            RegexBuilder::new(&normalized)
                .size_limit(16 * 1024 * 1024)
                .build()
        })
        .map_err(|error| CompileError {
            context: context.to_string(),
            message: error.to_string(),
        })
}

/// Adapts minor Go RE2 syntax differences to Rust's `regex` crate while
/// preserving exact match semantics:
///
/// 1. In Go RE2, an unescaped `{` that is not a valid `{m}`, `{m,}`, or `{m,n}`
///    quantifier (or appears where no atom precedes it, such as after `|` or `(`)
///    is treated as a literal `{`. Rust `regex` rejects unescaped `{` in those
///    positions.
/// 2. Go RE2 accepts `(?s:.)` and `\z` natively (so does Rust `regex`), while
///    character-class escapes like `\/` or `\"` are accepted by both.
#[derive(Clone, Copy, PartialEq, Eq)]
enum ParseState {
    InClass,
    ExpectAtom,
    AfterAtom,
}

fn normalize_re2_pattern(pattern: &str) -> String {
    let bytes = pattern.as_bytes();
    let mut out = String::with_capacity(pattern.len() + 8);
    let mut i = 0usize;
    let mut state = ParseState::ExpectAtom;

    while i < bytes.len() {
        let ch = pattern[i..].chars().next().expect("valid utf-8 boundary");
        let ch_len = ch.len_utf8();

        if ch == '\\' && i + 1 < bytes.len() {
            let next = pattern[i + 1..].chars().next().expect("valid utf-8");
            out.push('\\');
            out.push(next);
            i += 1 + next.len_utf8();
            if state != ParseState::InClass {
                state = if matches!(next, 'b' | 'B' | 'A' | 'z' | 'Z') {
                    ParseState::ExpectAtom
                } else {
                    ParseState::AfterAtom
                };
            }
            continue;
        }

        if state == ParseState::InClass {
            if ch == ']' {
                state = ParseState::AfterAtom;
            }
            out.push(ch);
            i += ch_len;
            continue;
        }

        match ch {
            '[' => {
                state = ParseState::InClass;
                out.push('[');
                i += 1;
                if i < bytes.len() && bytes[i] == b'^' {
                    out.push('^');
                    i += 1;
                }
                if i < bytes.len() && bytes[i] == b']' {
                    out.push(']');
                    i += 1;
                }
            }
            '{' => {
                let is_quantifier =
                    state == ParseState::AfterAtom && is_valid_quantifier(&bytes[i..]);
                if is_quantifier {
                    out.push('{');
                    state = ParseState::ExpectAtom;
                } else {
                    out.push_str("\\{");
                    state = ParseState::AfterAtom;
                }
                i += 1;
            }
            '(' | '|' | '^' | '*' | '+' | '?' | '$' => {
                out.push(ch);
                state = ParseState::ExpectAtom;
                i += 1;
            }
            _ => {
                out.push(ch);
                state = ParseState::AfterAtom;
                i += ch_len;
            }
        }
    }
    out
}

fn is_valid_quantifier(slice: &[u8]) -> bool {
    // Matches `{digits}`, `{digits,}`, or `{digits,digits}`.
    if slice.first() != Some(&b'{') {
        return false;
    }
    let mut i = 1usize;
    let start_min = i;
    while i < slice.len() && slice[i].is_ascii_digit() {
        i += 1;
    }
    if i == start_min {
        return false;
    }
    if i < slice.len() && slice[i] == b'}' {
        return true;
    }
    if i < slice.len() && slice[i] == b',' {
        i += 1;
        while i < slice.len() && slice[i].is_ascii_digit() {
            i += 1;
        }
        return i < slice.len() && slice[i] == b'}';
    }
    false
}

fn extract_secret_capture<'t>(
    captures: &regex::Captures<'t>,
    secret_group: Option<usize>,
) -> Option<regex::Match<'t>> {
    if let Some(group) = secret_group {
        if group > 0 {
            return captures.get(group);
        }
        return captures.get(0);
    }
    // Upstream Gitleaks behavior: when `secretGroup` is unset and there is a
    // single capturing group (or the first matched non-empty group), use that
    // group as the secret value for entropy & allowlist checks.
    if captures.len() > 1 {
        for idx in 1..captures.len() {
            if let Some(matched) = captures.get(idx) {
                if !matched.as_str().is_empty() {
                    return Some(matched);
                }
            }
        }
    }
    captures.get(0)
}

/// Computes Shannon entropy in bits per character, matching Gitleaks' detector.
#[must_use]
pub fn shannon_entropy(value: &str) -> f64 {
    if value.is_empty() {
        return 0.0;
    }
    let mut counts = [0usize; 256];
    let mut multi_byte: BTreeMap<char, usize> = BTreeMap::new();
    let mut total = 0usize;
    for ch in value.chars() {
        total += 1;
        let code = ch as u32;
        if code < 256 {
            counts[code as usize] += 1;
        } else {
            *multi_byte.entry(ch).or_default() += 1;
        }
    }
    let len = total as f64;
    let mut entropy = 0.0f64;
    for &count in &counts {
        if count > 0 {
            let p = count as f64 / len;
            entropy -= p * p.log2();
        }
    }
    for count in multi_byte.into_values() {
        let p = count as f64 / len;
        entropy -= p * p.log2();
    }
    entropy
}

/// Shortest match whose preview keeps any of its characters.
const REDACT_REVEAL_MIN_CHARS: usize = 24;

/// Produces a redacted preview that helps locate a finding without exposing the secret.
///
/// Only the first four characters of a match at least 24 characters long are kept,
/// a sixth of it at most. Those are usually the provider prefix (`ghp_`, `AKIA`, `sk_l`).
/// Anything shorter shows nothing.
#[must_use]
pub fn redact_secret(secret: &str) -> String {
    let chars: Vec<char> = secret.chars().collect();
    if chars.len() < REDACT_REVEAL_MIN_CHARS {
        return "***".to_string();
    }
    let prefix: String = chars[..4].iter().collect();
    format!("{prefix}…")
}

/// Stable, non-reversible 16-hex-char fingerprint for allowlisting one finding.
#[must_use]
pub fn secret_fingerprint(rule_id: &str, secret: &str) -> String {
    let mut hasher = Sha256::new();
    hasher.update(b"spacefast-secret-scan:v1\0");
    hasher.update(rule_id.as_bytes());
    hasher.update(b"\0");
    hasher.update(secret.as_bytes());
    let digest = format!("{:x}", hasher.finalize());
    digest[..16].to_string()
}

fn compute_policy_digest(config: &ScanConfig) -> String {
    let mut hasher = Sha256::new();
    hasher.update(format!("rev:{SCANNER_REVISION}\0").as_bytes());
    if let Ok(serialized) = serde_json::to_vec(config) {
        hasher.update(serialized);
    }
    format!("{:x}", hasher.finalize())
}

/// Returns `true` when the first 8 KiB of `bytes` contain a NUL byte.
#[must_use]
pub fn is_likely_binary(bytes: &[u8]) -> bool {
    let sample = &bytes[..bytes.len().min(8192)];
    sample.contains(&0)
}

fn contains_ascii_case_insensitive(haystack: &[u8], needle_lower: &[u8]) -> bool {
    haystack
        .windows(needle_lower.len())
        .any(|window| window.eq_ignore_ascii_case(needle_lower))
}

fn mask_base64_data_uris(text: &str) -> Cow<'_, str> {
    if !contains_ascii_case_insensitive(text.as_bytes(), b"base64,") {
        return Cow::Borrowed(text);
    }
    let mut matches = DATA_URI_BASE64.find_iter(text).peekable();
    if matches.peek().is_none() {
        return Cow::Borrowed(text);
    }
    let mut out = String::with_capacity(text.len());
    let mut last = 0usize;
    for matched in matches {
        out.push_str(&text[last..matched.start()]);
        for byte in matched.as_str().bytes() {
            out.push(if byte == b'\n' { '\n' } else { ' ' });
        }
        last = matched.end();
    }
    out.push_str(&text[last..]);
    Cow::Owned(out)
}

fn build_line_index(text: &str) -> Vec<usize> {
    let mut starts = vec![0usize];
    for (idx, byte) in text.bytes().enumerate() {
        if byte == b'\n' {
            starts.push(idx + 1);
        }
    }
    starts
}

fn line_context<'a>(
    text: &'a str,
    line_starts: &[usize],
    abs_start: usize,
    abs_end: usize,
) -> (usize, usize, &'a str) {
    let line_idx = match line_starts.binary_search(&abs_start) {
        Ok(idx) => idx,
        Err(idx) => idx.saturating_sub(1),
    };
    let line_start = line_starts[line_idx];
    let column = text[line_start..abs_start].chars().count() + 1;

    // For multi-line matches (e.g. PEM keys), span through the match's end line
    // (capped to avoid copying giant minified lines).
    let end_line_idx = match line_starts.binary_search(&abs_end) {
        Ok(idx) => idx,
        Err(idx) => idx.saturating_sub(1),
    };
    let raw_line_end = line_starts
        .get(end_line_idx + 1)
        .copied()
        .unwrap_or(text.len());
    const MAX_LINE_WINDOW: usize = 4096;
    let window_start = if abs_start.saturating_sub(line_start) > MAX_LINE_WINDOW / 2 {
        ceil_char_boundary(text, abs_start.saturating_sub(MAX_LINE_WINDOW / 2))
    } else {
        line_start
    };
    let window_end = if raw_line_end.saturating_sub(window_start) > MAX_LINE_WINDOW {
        floor_char_boundary(text, window_start + MAX_LINE_WINDOW)
    } else {
        raw_line_end
    };
    let slice = &text[window_start..window_end];
    (line_idx + 1, column, slice)
}

fn line_has_inline_allow(line: &str) -> bool {
    contains_ascii_case_insensitive(line.as_bytes(), b"gitleaks:allow")
}

fn path_matches(pattern: &str, path: &str) -> bool {
    let pattern = pattern.trim_start_matches('/');
    let path = path.trim_start_matches('/');
    if pattern == path {
        return true;
    }
    if let Some(prefix) = pattern.strip_suffix("/**") {
        return path == prefix
            || path
                .strip_prefix(prefix)
                .is_some_and(|rest| rest.starts_with('/'));
    }
    if let Some(prefix) = pattern.strip_suffix("/*") {
        return path
            .strip_prefix(prefix)
            .and_then(|rest| rest.strip_prefix('/'))
            .is_some_and(|rest| !rest.contains('/'));
    }
    if let Some(suffix) = pattern.strip_prefix('*') {
        return path.ends_with(suffix);
    }
    false
}

fn floor_char_boundary(text: &str, mut idx: usize) -> usize {
    idx = idx.min(text.len());
    while idx > 0 && !text.is_char_boundary(idx) {
        idx -= 1;
    }
    idx
}

fn ceil_char_boundary(text: &str, mut idx: usize) -> usize {
    idx = idx.min(text.len());
    while idx < text.len() && !text.is_char_boundary(idx) {
        idx += 1;
    }
    idx
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::BTreeSet;

    #[test]
    fn redacted_previews_keep_at_most_a_sixth_of_a_secret() {
        assert_eq!(redact_secret("abcdefghijk"), "***");
        assert_eq!(redact_secret("abcdefghijklmnopqrstuvw"), "***");
        assert_eq!(redact_secret("ghp_abcdefghijklmnopqrst"), "ghp_…");
        let long = "AKIA".to_string() + &"Z".repeat(36);
        assert_eq!(redact_secret(&long), "AKIA…");
    }

    #[test]
    fn every_vendored_rule_compiles_and_has_explicit_tier() {
        let config = ScanConfig::default_gitleaks();
        assert_eq!(config.rules.len(), 222);

        let scanner =
            Scanner::compile(&config).expect("every vendored rule compiles in Rust regex");
        assert_eq!(scanner.rule_count(), 222);

        let rule_ids: BTreeSet<&str> = config.rules.iter().map(|r| r.id.as_str()).collect();
        let tier_ids: BTreeSet<&str> = DEFAULT_RULE_TIERS.iter().map(|(id, _)| *id).collect();
        let missing: Vec<&str> = rule_ids.difference(&tier_ids).copied().collect();
        let stale: Vec<&str> = tier_ids.difference(&rule_ids).copied().collect();
        assert!(
            missing.is_empty(),
            "rules missing tier assignment: {missing:?}"
        );
        assert!(
            stale.is_empty(),
            "stale tier entries not in gitleaks.toml: {stale:?}"
        );

        // Verify DEFAULT_RULE_TIERS stays sorted so binary_search works.
        for pair in DEFAULT_RULE_TIERS.windows(2) {
            assert!(
                pair[0].0 < pair[1].0,
                "DEFAULT_RULE_TIERS out of order: {:?} >= {:?}",
                pair[0].0,
                pair[1].0
            );
        }
    }

    #[test]
    fn detects_tiered_secrets_and_never_exposes_raw_value() {
        let scanner = Scanner::default_gitleaks();
        let fake_ghp = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        let fake_aws = format!("{}{}", "AKIA", "Z7K4M7Q2R5PL3NW6");
        let fake_generic = "api_secret = \"x9K7mQ2vR8pL4nW6tY1zB5cJ\"";
        let content =
            format!("const gh = \"{fake_ghp}\";\nconst aws = \"{fake_aws}\";\n{fake_generic}\n");
        let outcome = scanner.scan_bytes("app.js", content.as_bytes(), ScanLimits::default(), None);

        assert!(outcome.findings.iter().any(|f| {
            f.rule_id == "github-pat"
                && f.severity == Severity::Block
                && f.line == 1
                && !f.redacted_preview.contains(&fake_ghp)
                && f.redacted_preview.starts_with("ghp_")
        }));
        assert!(outcome.findings.iter().any(|f| {
            f.rule_id == "aws-access-token"
                && f.severity == Severity::Block
                && f.line == 2
                && !f.redacted_preview.contains(&fake_aws)
        }));
        assert!(outcome.findings.iter().any(|f| {
            f.rule_id == "generic-api-key" && f.severity == Severity::Warn && f.line == 3
        }));
        assert!(outcome
            .findings
            .iter()
            .all(|f| f.redacted_preview.chars().count() <= 5));
    }

    #[test]
    fn public_keys_and_data_uris_do_not_block() {
        let scanner = Scanner::default_gitleaks();
        let stripe_pub = format!(
            "const key = \"{}{}\";\n",
            "pk_live_", "51H9xY2kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA"
        );
        let data_uri = format!(
            "const img = \"data:image/png;base64,{}\";\n",
            "ghp_9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA"
        );
        let out1 = scanner.scan_bytes(
            "index.js",
            stripe_pub.as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(
            out1.findings.iter().all(|f| f.severity != Severity::Block),
            "publishable Stripe key must never block: {:?}",
            out1.findings
        );
        let out2 = scanner.scan_bytes(
            "bundle.js",
            data_uri.as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(
            out2.findings.is_empty(),
            "base64 data URI must be masked: {:?}",
            out2.findings
        );
    }

    #[test]
    fn detects_multiline_and_json_escaped_private_keys() {
        let scanner = Scanner::default_gitleaks();
        let body = "MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7v3k2mN9pQ4xR8sT1uV6wX";
        let multiline =
            format!("-----BEGIN RSA PRIVATE KEY-----\n{body}\n-----END RSA PRIVATE KEY-----\n");
        let json_escaped = format!(
            "{{\"private_key\": \"-----BEGIN PRIVATE KEY-----\\n{body}\\n-----END PRIVATE KEY-----\\n\"}}"
        );
        let r1 = scanner.scan_bytes("key.txt", multiline.as_bytes(), ScanLimits::default(), None);
        assert!(r1
            .findings
            .iter()
            .any(|f| f.rule_id == "private-key" && f.severity == Severity::Block));

        let r2 = scanner.scan_bytes(
            "sa.json",
            json_escaped.as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(r2
            .findings
            .iter()
            .any(|f| f.rule_id == "private-key" && f.severity == Severity::Block));
    }

    #[test]
    fn inline_allow_and_override_allowlists_suppress_findings() {
        let scanner = Scanner::default_gitleaks();
        let token = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        let allowed_line = format!("const token = \"{token}\"; // gitleaks:allow\n");
        let out = scanner.scan_bytes(
            "app.js",
            allowed_line.as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(
            out.findings.is_empty(),
            "gitleaks:allow should suppress line"
        );

        let plain_line = format!("const val = \"{token}\";\n");
        let raw = scanner.scan_bytes("app.js", plain_line.as_bytes(), ScanLimits::default(), None);
        assert_eq!(raw.findings.len(), 1);
        let fp = raw.findings[0].fingerprint.clone();

        let by_fp = AllowlistOverride {
            fingerprints: vec![fp],
            ..Default::default()
        };
        let suppressed = scanner.scan_bytes(
            "app.js",
            plain_line.as_bytes(),
            ScanLimits::default(),
            Some(&by_fp),
        );
        assert!(suppressed.findings.is_empty());

        let by_entry = AllowlistOverride {
            entries: vec![AllowlistEntry {
                path: Some("docs/**".into()),
                rule_id: Some("github-pat".into()),
                fingerprint: None,
            }],
            ..Default::default()
        };
        let suppressed_doc = scanner.scan_bytes(
            "docs/guide.md",
            plain_line.as_bytes(),
            ScanLimits::default(),
            Some(&by_entry),
        );
        assert!(suppressed_doc.findings.is_empty());
        let not_suppressed_app = scanner.scan_bytes(
            "app.js",
            plain_line.as_bytes(),
            ScanLimits::default(),
            Some(&by_entry),
        );
        assert_eq!(not_suppressed_app.findings.len(), 1);
    }

    #[test]
    fn handles_minified_lines_and_binary_blobs_safely() {
        let scanner = Scanner::default_gitleaks();
        let binary = b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRghp_9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA";
        let bin_out = scanner.scan_bytes("logo.png", binary, ScanLimits::default(), None);
        assert!(bin_out.findings.is_empty());

        let pad = "a".repeat(70_000);
        let token = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        let minified = format!("{pad};const k=\"{token}\";{pad}");
        let min_out = scanner.scan_bytes(
            "dist/app.min.js",
            minified.as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(
            min_out
                .findings
                .iter()
                .any(|f| f.rule_id == "github-pat" && f.line == 1),
            "should detect secret across chunk boundary in minified single-line bundle"
        );
    }

    #[test]
    fn supports_custom_org_literal_rules() {
        let marker = format!("{}{}", "ACME_INTERNAL_", "9kX7mQ2vR8pL4nW6");
        let config = ScanConfig::default_gitleaks().with_literal_rules([(
            "acme-internal-token".into(),
            "Detected an Acme internal token.".into(),
            marker.clone(),
            Severity::Block,
        )]);
        let scanner = Scanner::compile(&config).expect("custom literal rule compiles");
        let out = scanner.scan_bytes(
            "config.js",
            format!("export const key = '{marker}';").as_bytes(),
            ScanLimits::default(),
            None,
        );
        assert!(out
            .findings
            .iter()
            .any(|f| f.rule_id == "acme-internal-token" && f.severity == Severity::Block));
    }

    #[test]
    fn block_rules_are_never_blinded_by_warn_finding_cap() {
        let mut config = ScanConfig::default_gitleaks();
        let high_entropy_warn_lines: String = (0..40)
            .map(|i| format!("const api_key_{i} = \"aB3dE5fG7hI9jK1lM3nO5pQ7rS9tU1v{i:02}\";\n"))
            .collect();
        let ghp_token = format!("{}{}", "ghp_", "9kX7mQ2vR8pL4nW6tY1zB5cJ3hF0dS8uE2qA");
        let content = format!("{high_entropy_warn_lines}const gh = \"{ghp_token}\";\n");

        // Ensure custom late-indexed Block rule also runs ahead of Warn rules.
        let marker = format!("{}{}", "LATE_BLOCK_", "9kX7mQ2vR8pL4nW6tY1z");
        config = config.with_literal_rules([(
            "late-block-token".into(),
            "Late-added block rule".into(),
            marker.clone(),
            Severity::Block,
        )]);
        let content = format!("{content}const late = \"{marker}\";\n");

        let scanner = Scanner::compile_lazy(&config).expect("compile");
        let out = scanner.scan_bytes(
            "noisy.js",
            content.as_bytes(),
            ScanLimits {
                max_findings_per_file: 5,
                ..ScanLimits::default()
            },
            None,
        );
        assert!(
            out.truncated,
            "hitting finding cap on single chunk must mark truncated"
        );
        assert!(
            out.findings
                .iter()
                .any(|f| f.rule_id == "github-pat" && f.severity == Severity::Block),
            "Block rule github-pat must not be blinded by earlier Warn hits: {:?}",
            out.findings
        );
        assert!(
            out.findings
                .iter()
                .any(|f| f.rule_id == "late-block-token" && f.severity == Severity::Block),
            "Late-registered Block rule must run ahead of Warn rules: {:?}",
            out.findings
        );

        // Multi-chunk regression: warning storm in chunk 1 fills the finding cap
        // before chunk 2, which holds a Tier-1 Block secret.
        let chunk_pad = "// filler\n".repeat(1200);
        let multi_chunk =
            format!("{high_entropy_warn_lines}{chunk_pad}const gh = \"{ghp_token}\";\n");
        let multi_out = scanner.scan_bytes(
            "multi_chunk.js",
            multi_chunk.as_bytes(),
            ScanLimits {
                max_findings_per_file: 5,
                chunk_bytes: 4096,
                chunk_overlap_bytes: 512,
                ..ScanLimits::default()
            },
            None,
        );
        assert!(multi_out.truncated);
        assert!(multi_out.findings.len() <= 5);
        assert!(
            multi_out
                .findings
                .iter()
                .any(|f| f.rule_id == "github-pat" && f.severity == Severity::Block),
            "Warn cap in chunk 1 must never suppress Block secret in chunk 2: {:?}",
            multi_out.findings
        );
    }
}
