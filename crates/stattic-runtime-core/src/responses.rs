//! The schema-v4 response tables.
//!
//! One compiled array answers a request: status, the complete intended header
//! set, the blob that carries the body, a precomputed validator, the lane that
//! sends it, and an optional action. Nothing is resolved at request time — the
//! serve path reads and sends.

use serde_json::{json, Map, Value};
use std::collections::{BTreeMap, BTreeSet};
use std::path::Path;
use std::sync::LazyLock;

use regex::Regex;

use crate::access::{bucket_pattern_rules, rule_is_request_dependent};
use crate::artifacts::CompiledListing;
use crate::finalize::{
    content_mtime, create_dir_all, immutable_path, invalid_with_details, mime_for_path, php_like,
    sha256, write_bytes, write_json, FileMeta, Result,
};
use crate::protocol::{
    ACCEL_SURVIVING_HEADERS, ARTIFACT_HASH_PREFIX_LEN, ARTIFACT_SCHEMA_VERSION, CACHE_CLASS_HTML,
    CACHE_CLASS_IMMUTABLE, CACHE_CLASS_REVALIDATE, PHP_LANE_BODY_MAX_BYTES,
    PROVIDER_ASSET_EXTENSIONS, RESPONSE_ACTION_LISTING, RESPONSE_ACTION_NOT_FOUND,
    RESPONSE_ACTION_PHP, RESPONSE_ENTRY_ACTION, RESPONSE_ENTRY_ALLOWLISTED_EXT,
    RESPONSE_ENTRY_BLOB, RESPONSE_ENTRY_CACHE_CLASS, RESPONSE_ENTRY_ETAG, RESPONSE_ENTRY_HEADERS,
    RESPONSE_ENTRY_LANE, RESPONSE_ENTRY_LENGTH, RESPONSE_ENTRY_RULES_FIRST, RESPONSE_ENTRY_STATUS,
    RESPONSE_KEY_NOT_FOUND, RESPONSE_KEY_NOT_FOUND_PREFIX, RESPONSE_KEY_ROBOTS, RESPONSE_KEY_RULES,
    RESPONSE_KEY_SPA, RESPONSE_LANE_ACCEL, RESPONSE_LANE_PHP, RESPONSE_TABLE_BASENAME,
    RESPONSE_TABLE_MAX_BYTES, RESPONSE_TABLE_SINGLE_KEY, RESPONSE_TABLE_SPLIT_BYTES,
    THEME_STYLESHEET_URL, VERSION_ROOT_BASENAME, VERSION_ROOT_POINTER_FILE,
};
use crate::serving_paths::is_private_serving_path;

/// The deny-all `robots.txt` a noindex or preview host serves. It reduces crawl
/// load; the noindex that actually binds is the `X-Robots-Tag` on the HTML
/// entries.
pub(crate) const DENY_ALL_ROBOTS: &str = "User-agent: *\nDisallow: /\n";

/// The client URL `\0robots` answers at.
const ROBOTS_CLIENT_URL: &str = "/robots.txt";

/// One compiled response.
#[derive(Debug, Clone)]
pub(crate) struct ResponseEntry {
    pub status: u64,
    /// Platform headers only — never a publisher `_headers` value. See
    /// [`RuleIndex`] for why those cannot be compiled in.
    pub headers: BTreeMap<String, String>,
    /// The lowercase names every ordered `_headers` rule whose matcher can
    /// claim this key could SET at send time. The values are templated against
    /// the requested URL and so are not knowable here, but the lane gate, the
    /// validator kind and the unservable-header check need the complete
    /// intended header set. Conservative by construction: a rule that could add
    /// a header outside [`ACCEL_SURVIVING_HEADERS`] forces the PHP lane whether
    /// or not it fires.
    pub rule_header_names: BTreeSet<String>,
    pub blob: Option<String>,
    pub length: u64,
    pub cache_class: Option<&'static str>,
    pub action: Option<Value>,
    pub rules_first: bool,
    /// An HTML body, which is always the PHP lane.
    pub html: bool,
}

impl ResponseEntry {
    fn bodyless(status: u64, headers: BTreeMap<String, String>) -> Self {
        Self {
            status,
            headers,
            rule_header_names: BTreeSet::new(),
            blob: None,
            length: 0,
            cache_class: Some(CACHE_CLASS_REVALIDATE),
            action: None,
            rules_first: false,
            html: false,
        }
    }

    fn with_body(
        status: u64,
        headers: BTreeMap<String, String>,
        blob: String,
        length: u64,
        cache_class: &'static str,
    ) -> Self {
        Self {
            status,
            headers,
            rule_header_names: BTreeSet::new(),
            blob: Some(blob),
            length,
            cache_class: Some(cache_class),
            action: None,
            rules_first: false,
            html: false,
        }
    }

    /// Every header name this response intends to send: the compiled platform
    /// set plus the names the residue's `_headers` rules can add at send time.
    fn intended_header_names(&self) -> BTreeSet<&str> {
        self.headers
            .keys()
            .chain(self.rule_header_names.iter())
            .map(String::as_str)
            .collect()
    }

    /// The lane rule, compiled for the OPEN-space case.
    ///
    /// Accel only when all of it holds: status 200 with a local file; every
    /// intended header inside [`ACCEL_SURVIVING_HEADERS`]; and either the client
    /// URL extension is outside the provider allowlist, or the intended policy
    /// is exactly the public-immutable one the provider would impose anyway.
    ///
    /// Protected spaces are not compiled in: openness is a space property and
    /// the version tables are immutable. The serve path forces the PHP lane on
    /// a protected space wherever `xa` is set.
    fn lane(&self, key: &str) -> u64 {
        if self.blob.is_none() || self.status != 200 || self.html {
            return RESPONSE_LANE_PHP;
        }
        if self
            .intended_header_names()
            .iter()
            .any(|name| !ACCEL_SURVIVING_HEADERS.contains(name))
        {
            return RESPONSE_LANE_PHP;
        }
        if allowlisted_extension(key) && self.cache_class != Some(CACHE_CLASS_IMMUTABLE) {
            // The provider would rewrite this body to public ten-year + ACAO:*,
            // so accel would serve a policy we did not compile.
            return RESPONSE_LANE_PHP;
        }
        RESPONSE_LANE_ACCEL
    }

    /// The intended headers that no lane can deliver for this response: above
    /// the PHP-lane ceiling the body cannot go through PHP, and the accel lane
    /// drops everything outside the survivor set.
    fn unservable_headers(&self) -> Vec<&str> {
        if self.blob.is_none() || self.length <= PHP_LANE_BODY_MAX_BYTES {
            return Vec::new();
        }
        self.intended_header_names()
            .into_iter()
            .filter(|name| !ACCEL_SURVIVING_HEADERS.contains(name))
            .collect()
    }

    fn to_value(&self, key: &str) -> Value {
        let lane = self.lane(key);
        let mut entry = Map::new();
        entry.insert(RESPONSE_ENTRY_STATUS.into(), json!(self.status));
        entry.insert(
            RESPONSE_ENTRY_HEADERS.into(),
            Value::Object(
                self.headers
                    .iter()
                    .map(|(name, value)| (name.clone(), Value::String(value.clone())))
                    .collect(),
            ),
        );
        entry.insert(
            RESPONSE_ENTRY_BLOB.into(),
            self.blob.clone().map_or(Value::Null, Value::String),
        );
        if let Some(sha) = &self.blob {
            // The validator's kind follows the compiled lane. nginx derives
            // `<hexmtime>-<hexsize>` from the placed file and never sees our
            // digest, so an accel entry has to carry exactly that value; a PHP
            // entry carries the real strong ETag.
            //
            // Either kind is emitted UNQUOTED: the serve path owns the quoting,
            // so neither shape can arrive double-quoted.
            entry.insert(
                RESPONSE_ENTRY_ETAG.into(),
                json!(if lane == RESPONSE_LANE_ACCEL {
                    format!("{:x}-{:x}", content_mtime(sha), self.length)
                } else {
                    sha.clone()
                }),
            );
        }
        entry.insert(RESPONSE_ENTRY_LENGTH.into(), json!(self.length));
        entry.insert(RESPONSE_ENTRY_LANE.into(), json!(lane));
        if let Some(class) = self.cache_class {
            entry.insert(RESPONSE_ENTRY_CACHE_CLASS.into(), json!(class));
        }
        if self.blob.is_some() && allowlisted_extension(key) {
            entry.insert(RESPONSE_ENTRY_ALLOWLISTED_EXT.into(), json!(1));
        }
        if let Some(action) = &self.action {
            entry.insert(RESPONSE_ENTRY_ACTION.into(), action.clone());
        }
        if self.rules_first {
            entry.insert(RESPONSE_ENTRY_RULES_FIRST.into(), json!(1));
        }
        Value::Object(entry)
    }
}

/// The client URL an entry answers at, when the compiler can know it.
///
/// Both the lane gate and the `xa` flag read the CLIENT URL, never the blob
/// name — the live probe proved the provider's asset rewrite keys on the URL the
/// visitor asked for, so an extensionless accel target at a `.css` URL is still
/// rewritten. For a published path the table key IS that URL; for `\0spa` and
/// the nearest-404 chain it is whatever the visitor asked for, which the
/// compiler cannot know.
fn client_url(key: &str) -> Option<&str> {
    match key {
        RESPONSE_KEY_ROBOTS => Some(ROBOTS_CLIENT_URL),
        _ if key.starts_with('\0') => None,
        _ => Some(key),
    }
}

/// Whether the client URL ends in an extension the provider rewrites.
///
/// An unknowable client URL answers `true`: the flag exists to keep private
/// bytes off the accel lane, and guessing low there is the one mistake that
/// leaks.
fn allowlisted_extension(key: &str) -> bool {
    let Some(url) = client_url(key) else {
        return true;
    };
    let name = url.rsplit('/').next().unwrap_or_default();
    let Some((_, extension)) = name.rsplit_once('.') else {
        return false;
    };
    PROVIDER_ASSET_EXTENSIONS.contains(&extension.to_ascii_lowercase().as_str())
}

/// A filename that already carries a content hash, as bundlers emit.
///
/// Precision over recall: a false positive pins a URL in every visitor's
/// browser for a year, while a false negative costs one revalidation. So
/// `photo_20240115.jpg` (all digits), `hero-image1.jpg` (a word) and
/// `jquery-3.7.1.min.js` (a version) stay out.
static HEX_DIGEST_NAME: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"(?i)^.*[.\-_]([0-9a-f]{8,32})\.[a-z0-9]{1,8}$").expect("hex digest name regex")
});
static CHUNK_ID_NAME: LazyLock<Regex> = LazyLock::new(|| {
    Regex::new(r"^.*[.\-_]([A-Za-z0-9_-]{6,32})\.[A-Za-z0-9]{1,8}$").expect("chunk id name regex")
});

fn carries_content_hash(key: &str) -> bool {
    let name = key.rsplit('/').next().unwrap_or_default();
    // `app.3f9c2d1a.js`, `styles.abc123def456.css` — a hex digest needs both a
    // letter and a digit, or it is a date or a counter.
    if let Some(digest) = HEX_DIGEST_NAME.captures(name).and_then(|hit| hit.get(1)) {
        let digest = digest.as_str();
        if digest.bytes().any(|byte| byte.is_ascii_alphabetic())
            && digest.bytes().any(|byte| byte.is_ascii_digit())
        {
            return true;
        }
    }
    // `chunk-XK2FA9.css`, `index-DiwrgTda.js` — a base36/base64url chunk id.
    // An uppercase letter is what separates one from an English word, and it
    // needs a second character class so `-README.css` does not qualify.
    let Some(chunk) = CHUNK_ID_NAME.captures(name).and_then(|hit| hit.get(1)) else {
        return false;
    };
    let chunk = chunk.as_str();
    chunk.bytes().any(|byte| byte.is_ascii_uppercase())
        && chunk
            .bytes()
            .any(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit())
}

/// Everything the table compiler needs, already resolved: the compiler reads,
/// it does not decide policy.
pub(crate) struct ResponseCompileInput<'a> {
    pub files: &'a BTreeMap<String, FileMeta>,
    pub private: &'a BTreeSet<String>,
    pub serving_config: &'a Map<String, Value>,
    pub redirects_exact: &'a Map<String, Value>,
    pub redirects_pattern: &'a [Value],
    pub headers_exact: &'a Map<String, Value>,
    pub headers_pattern: &'a [Value],
    pub listings: &'a [CompiledListing],
    pub zero_actions: &'a Map<String, Value>,
    pub robots_blob: Option<(String, u64)>,
    /// The host class this version serves under is a route property, not a
    /// version property — but a preview host only ever serves its own version,
    /// so compiling the noindex in is honest and costs no request-time work.
    pub noindex_host: bool,
}

/// Compiles every request key a published version can answer.
pub(crate) fn compile_response_table(
    input: &ResponseCompileInput<'_>,
) -> BTreeMap<String, ResponseEntry> {
    let mut table = BTreeMap::<String, ResponseEntry>::new();
    let rules = RuleIndex::new(
        input.redirects_exact,
        input.redirects_pattern,
        input.headers_exact,
        input.headers_pattern,
    );
    let index_name = input
        .serving_config
        .get("index")
        .and_then(Value::as_str)
        .unwrap_or("index.html")
        .to_string();
    let clean_urls = input
        .serving_config
        .get("clean_urls")
        .and_then(Value::as_bool)
        .unwrap_or(false);

    for (path, meta) in input.files {
        if input.private.contains(path) || is_private_serving_path(path) {
            // A private path answers 404 rather than falling through to the SPA
            // fallback, which would otherwise reveal that it exists by
            // answering differently from a genuine miss.
            table.insert(
                request_key(path),
                ResponseEntry {
                    action: Some(json!({ "t": RESPONSE_ACTION_NOT_FOUND })),
                    ..ResponseEntry::bodyless(404, BTreeMap::new())
                },
            );
            continue;
        }
        if php_function_route(path).is_some() {
            // A functions-route handler is code, not content: its source never
            // serves. Same 404 action as a private path, so probing the source
            // URL is indistinguishable from a genuine miss.
            table.insert(
                request_key(path),
                ResponseEntry {
                    action: Some(json!({ "t": RESPONSE_ACTION_NOT_FOUND })),
                    ..ResponseEntry::bodyless(404, BTreeMap::new())
                },
            );
            continue;
        }
        let key = request_key(path);
        table.insert(
            key.clone(),
            file_entry(&key, path, meta, input, &rules, 200),
        );
        for alias in clean_url_keys(path, &index_name, clean_urls) {
            match alias {
                CleanUrl::Serve(key) => {
                    // Resolved against the ALIAS key: the cache class and the
                    // settable header names both follow the URL the visitor
                    // asks for, and a `_headers` rule can name one and not the
                    // other.
                    table
                        .entry(key.clone())
                        .or_insert_with(|| file_entry(&key, path, meta, input, &rules, 200));
                }
                CleanUrl::Canonical { from, to } => {
                    table
                        .entry(from)
                        .or_insert_with(|| redirect_entry(308, &to));
                }
            }
        }
    }

    for listing in input.listings {
        let key = listing_key(&listing.directory);
        let mut headers = BTreeMap::new();
        headers.insert("content-type".into(), "text/html; charset=utf-8".into());
        headers.insert("x-content-type-options".into(), "nosniff".into());
        apply_noindex(&mut headers, input.noindex_host);
        let entry = ResponseEntry {
            html: true,
            action: Some(json!({
                "t": RESPONSE_ACTION_LISTING,
                "dir": listing.directory,
                "shell": listing.shell_sha,
                "rows": listing
                    .rows
                    .iter()
                    .map(|row| json!([row.path, row.sha]))
                    .collect::<Vec<_>>(),
            })),
            ..ResponseEntry::with_body(
                200,
                headers,
                listing.page_sha.clone(),
                listing.page_length,
                CACHE_CLASS_HTML,
            )
        };
        table.insert(key.clone(), entry);
        if !listing.directory.is_empty() {
            let canonical = format!("/{}", listing.directory);
            table
                .entry(canonical)
                .or_insert_with(|| redirect_entry(308, &key));
        }
    }

    // PHP function routes execute at their derived key (`t=php`, dispatched to
    // runtime/php-functions.php). Inserted after the file entries so an
    // explicitly committed handler outranks a clean-URL alias at the same key,
    // and before exact redirects and Zero actions so both of those still win —
    // the same precedence the JS Functions lane gets by running last.
    for (path, meta) in input.files {
        if input.private.contains(path) || is_private_serving_path(path) {
            continue;
        }
        let Some(route) = php_function_route(path) else {
            continue;
        };
        let mut entry = ResponseEntry::bodyless(200, BTreeMap::new());
        entry.action = Some(json!({
            "t": RESPONSE_ACTION_PHP,
            "src": path,
            "sha": meta.sha256,
        }));
        entry.cache_class = Some(CACHE_CLASS_REVALIDATE);
        table.insert(route, entry);
    }

    // Exact redirects the compiler can answer without walking the ordered list.
    for (request_path, compiled) in rules.compiled_exact_redirects() {
        table.insert(request_path, compiled);
    }

    for (path, action) in input.zero_actions {
        let mut entry = ResponseEntry::bodyless(200, BTreeMap::new());
        entry.action = Some(action.clone());
        entry.cache_class = Some(CACHE_CLASS_REVALIDATE);
        table.insert(request_key(path), entry);
    }

    // Nearest-404 chain: `\0404:<dir>` for a directory that publishes its own
    // 404 page, `\0404` for the root one.
    for (path, meta) in input.files {
        let Some(directory) = path.strip_suffix("404.html") else {
            continue;
        };
        if input.private.contains(path) || is_private_serving_path(path) {
            continue;
        }
        let directory = directory.trim_matches('/');
        let key = if directory.is_empty() {
            RESPONSE_KEY_NOT_FOUND.to_string()
        } else {
            format!("{RESPONSE_KEY_NOT_FOUND_PREFIX}{directory}")
        };
        table.insert(
            key,
            file_entry(&request_key(path), path, meta, input, &rules, 404),
        );
    }

    if let Some(fallback) = input
        .serving_config
        .get("fallback")
        .and_then(Value::as_object)
    {
        let path = fallback.get("path").and_then(Value::as_str).unwrap_or("");
        let status = fallback
            .get("status")
            .and_then(Value::as_u64)
            .unwrap_or(200);
        if let Some(meta) = input.files.get(path) {
            if !input.private.contains(path) && !is_private_serving_path(path) {
                table.insert(
                    RESPONSE_KEY_SPA.into(),
                    file_entry(&request_key(path), path, meta, input, &rules, status),
                );
            }
        }
    }

    // The deny-all `robots.txt` is compiled for every version because whether it
    // may answer is a HOST property the immutable version tables cannot see: the
    // serve side gates it, answering `/robots.txt` from `\0robots` only on a
    // noindex host. It cannot shadow a published `robots.txt`, which owns the
    // ordinary `/robots.txt` key.
    if let Some((sha, length)) = &input.robots_blob {
        let mut headers = BTreeMap::new();
        headers.insert("content-type".into(), "text/plain; charset=utf-8".into());
        table.insert(
            RESPONSE_KEY_ROBOTS.into(),
            ResponseEntry::with_body(200, headers, sha.clone(), *length, CACHE_CLASS_REVALIDATE),
        );
    }

    if let Some(residue) = rules.residue() {
        let mut entry = ResponseEntry::bodyless(0, BTreeMap::new());
        entry.action = Some(residue);
        entry.cache_class = None;
        table.insert(RESPONSE_KEY_RULES.into(), entry);
    }

    // The ordered rules bind to a KEY, not to an entry kind, and one sweep over
    // the finished table is the only shape that cannot forget a kind.
    //
    // `\0`-prefixed keys are skipped: they answer at a client URL the compiler
    // cannot see, so `file_entry` already resolved them against the published
    // path they were built from, and the serve path never reads `r` off one.
    for (key, entry) in &mut table {
        if key.starts_with('\0') {
            continue;
        }
        entry.rules_first = rules.claims(key);
        entry.rule_header_names = rules.settable_header_names(key);
    }

    table
}

/// A published file's own request key.
fn request_key(path: &str) -> String {
    format!("/{}", path.trim_start_matches('/'))
}

/// The exact route a committed `functions/<name>.php` executes at, mirroring
/// the file-router convention of the JS Functions lane
/// (packages/cli/src/functions-routes.ts `fileRouteFromRelativePath`):
/// `functions/hello.php` → `/hello`, `functions/api/index.php` → `/api`,
/// `functions/index.php` → `/`. Exact segments only — a bracketed pattern
/// segment (`[id]`, `[...rest]`) cannot be a table key and is NOT routed in
/// this slice, so such a file keeps the inert-attachment entry like any other
/// stray `.php`.
fn php_function_route(path: &str) -> Option<String> {
    let stem = path.strip_prefix("functions/")?.strip_suffix(".php")?;
    let mut segments: Vec<&str> = stem.split('/').collect();
    if segments.last() == Some(&"index") {
        segments.pop();
    }
    if segments.iter().any(|segment| {
        segment.is_empty()
            || segment.contains('[')
            || segment.contains(']')
            || segment.starts_with(':')
            || segment.starts_with('.')
    }) {
        return None;
    }
    Some(if segments.is_empty() {
        "/".into()
    } else {
        format!("/{}", segments.join("/"))
    })
}

fn listing_key(directory: &str) -> String {
    if directory.is_empty() {
        "/".into()
    } else {
        format!("/{directory}/")
    }
}

enum CleanUrl {
    Serve(String),
    Canonical { from: String, to: String },
}

/// The clean-URL and canonical-slash keys one published path owns. The runtime
/// owns this redirect because `canonicalize_aliases` is pinned off on wp.cloud.
fn clean_url_keys(path: &str, index_name: &str, clean_urls: bool) -> Vec<CleanUrl> {
    let mut keys = Vec::new();
    if path == index_name {
        keys.push(CleanUrl::Serve("/".into()));
        return keys;
    }
    if let Some(directory) = path
        .strip_suffix(index_name)
        .and_then(|directory| directory.strip_suffix('/'))
    {
        let served = format!("/{directory}/");
        keys.push(CleanUrl::Serve(served.clone()));
        keys.push(CleanUrl::Canonical {
            from: format!("/{directory}"),
            to: served,
        });
        return keys;
    }
    if clean_urls {
        if let Some(stem) = path.strip_suffix(".html") {
            let served = format!("/{stem}");
            keys.push(CleanUrl::Serve(served.clone()));
            keys.push(CleanUrl::Canonical {
                from: format!("/{stem}/"),
                to: served,
            });
        }
    }
    keys
}

fn redirect_entry(status: u64, location: &str) -> ResponseEntry {
    let mut headers = BTreeMap::new();
    headers.insert("location".into(), location.to_string());
    ResponseEntry::bodyless(status, headers)
}

/// The noindex a preview host needs. It is a platform header, so the serve path
/// re-asserts it after the residue runs and a publisher `remove` cannot strip it
/// off a preview host.
fn apply_noindex(headers: &mut BTreeMap<String, String>, noindex_host: bool) {
    if noindex_host {
        headers.insert("x-robots-tag".into(), "noindex, nofollow".into());
    }
}

/// The entry a published file answers with at `key`. `key` is the request key
/// the ordered rules and the client-URL gates are resolved against; it is the
/// file's own path for an ordinary entry, the alias for a clean-URL alias, and
/// the source path for the `\0` specials whose client URL is unknowable.
fn file_entry(
    key: &str,
    path: &str,
    meta: &FileMeta,
    input: &ResponseCompileInput<'_>,
    rules: &RuleIndex,
    status: u64,
) -> ResponseEntry {
    let mime = mime_for_path(path, Some(&meta.mime));
    let is_html = mime.starts_with("text/html");
    let forced = php_like(path);
    let mut headers = BTreeMap::<String, String>::new();
    headers.insert(
        "content-type".into(),
        if forced {
            if path.to_ascii_lowercase().ends_with(".phar") {
                "application/octet-stream".into()
            } else {
                "text/plain; charset=utf-8".into()
            }
        } else {
            mime.clone()
        },
    );
    if forced {
        // Survives the accel lane and still stops a browser treating tenant
        // content as a page; it replaces the `X-Content-Type-Options` nginx
        // drops.
        headers.insert("content-disposition".into(), "attachment".into());
    }
    if is_html {
        headers.insert("x-content-type-options".into(), "nosniff".into());
    }
    apply_noindex(&mut headers, input.noindex_host && is_html);
    let rule_header_names = rules.settable_header_names(key);

    let cache_class = if key == THEME_STYLESHEET_URL {
        // A theme change swaps the blob behind this exact URL and purges it, so
        // its policy is not the publisher's to pin. The serve path composes
        // `cache-control` from the class after the residue runs, so shipping a
        // class is what keeps a publisher rule from winning here.
        Some(CACHE_CLASS_REVALIDATE)
    } else if rule_header_names.contains("cache-control") {
        // Emitting no class tells the serve path to leave the publisher rule's
        // value alone.
        None
    } else if is_html {
        Some(CACHE_CLASS_HTML)
    } else if immutable_path(path) || carries_content_hash(key) {
        Some(CACHE_CLASS_IMMUTABLE)
    } else {
        Some(CACHE_CLASS_REVALIDATE)
    };

    ResponseEntry {
        status,
        headers,
        rule_header_names,
        blob: Some(meta.sha256.clone()),
        length: meta.size,
        cache_class,
        action: None,
        rules_first: rules.claims(key),
        html: is_html,
    }
}

/// The redirect and `_headers` rules, indexed for compile-time evaluation.
///
/// No `_headers` value is compiled into an entry. Every rule ships whole in the
/// `\0rules` residue, in publisher order, with its matcher and its templated
/// value intact, and PHP applies it at send time against the URL the visitor
/// asked for — a value like `X-Asset: name=:splat` has no meaning until a
/// request supplies the capture, and a rewrite moves an entry's key away from
/// the requested URL that `_headers` is contractually matched against.
///
/// What the compiler resolves is the header NAMES a rule could set on a key
/// ([`RuleIndex::settable_header_names`]) and the ordered redirect list, whose
/// first-match-wins semantics a per-path answer would invert.
struct RuleIndex<'a> {
    redirects_exact: &'a Map<String, Value>,
    redirects_pattern: &'a [Value],
    /// Every exact-redirect key under [`normalized_rule_key`], which is the
    /// string the serve-time walk looks them up by.
    redirect_exact_keys: BTreeSet<String>,
    redirect_matchers: Vec<(i64, Regex)>,
    headers_exact: &'a Map<String, Value>,
    headers_pattern: &'a [Value],
    /// Every `_headers` rule as `(matcher, lowercase names it can SET)`.
    /// Removals are not here: a removal cannot introduce a header the lane
    /// could fail to deliver, and the platform headers it targets are
    /// re-asserted after the residue runs.
    header_setters: Vec<(HeaderMatcher, BTreeSet<String>)>,
}

enum HeaderMatcher {
    Exact(String),
    Pattern(Regex),
    /// A rule whose matcher the compiler cannot evaluate. It matches every key,
    /// so its header names land on every entry and the conservative side of
    /// every gate is the one taken.
    Opaque,
}

impl HeaderMatcher {
    fn matches(&self, key: &str) -> bool {
        match self {
            Self::Exact(path) => path == key,
            Self::Pattern(regex) => regex.is_match(key),
            Self::Opaque => true,
        }
    }
}

impl<'a> RuleIndex<'a> {
    fn new(
        redirects_exact: &'a Map<String, Value>,
        redirects_pattern: &'a [Value],
        headers_exact: &'a Map<String, Value>,
        headers_pattern: &'a [Value],
    ) -> Self {
        // Compiling a regex costs orders of magnitude more than matching one,
        // and every compiled key would otherwise recompile the whole list.
        let redirect_matchers = redirects_pattern
            .iter()
            .filter_map(|rule| {
                let regex = rule
                    .get("regex")
                    .and_then(Value::as_str)
                    .filter(|regex| !regex.is_empty())
                    .and_then(|regex| Regex::new(regex).ok())?;
                let order = rule
                    .get("order")
                    .and_then(Value::as_i64)
                    .unwrap_or(i64::MAX);
                Some((order, regex))
            })
            .collect();

        let mut ordered: Vec<&Value> = headers_exact
            .values()
            .flat_map(|bucket| bucket.as_array().into_iter().flatten())
            .chain(headers_pattern.iter())
            .collect();
        ordered.sort_by_key(|rule| {
            rule.get("order")
                .and_then(Value::as_i64)
                .unwrap_or(i64::MAX)
        });

        let mut header_setters = Vec::new();
        for rule in ordered {
            let names: BTreeSet<String> = rule
                .get("operations")
                .and_then(Value::as_array)
                .into_iter()
                .flatten()
                .filter(|operation| operation.get("kind").and_then(Value::as_str) == Some("set"))
                .filter_map(|operation| operation.get("name").and_then(Value::as_str))
                .map(str::to_ascii_lowercase)
                .collect();
            if names.is_empty() {
                continue;
            }
            header_setters.push((header_matcher(rule), names));
        }

        Self {
            redirects_exact,
            redirects_pattern,
            redirect_exact_keys: redirects_exact
                .keys()
                .map(|path| normalized_rule_key(path))
                .collect(),
            redirect_matchers,
            headers_exact,
            headers_pattern,
            header_setters,
        }
    }

    /// Every header name a `_headers` rule could set on this key: the union over
    /// MATCHING rules, not the outcome of evaluating them, so a rule whose host
    /// condition will not hold still counts. Over-reporting costs one entry the
    /// accel lane; under-reporting silently drops a header the publisher asked
    /// for.
    fn settable_header_names(&self, key: &str) -> BTreeSet<String> {
        self.header_setters
            .iter()
            .filter(|(matcher, _)| matcher.matches(key))
            .flat_map(|(_, names)| names.iter().cloned())
            .collect()
    }

    /// The exact redirects that can answer without the ordered walk. A rule only
    /// qualifies when nothing earlier could have claimed the path: redirects are
    /// first-match-wins over ONE ordered list, and a precomputed answer that
    /// jumps the queue silently inverts that.
    fn compiled_exact_redirects(&self) -> Vec<(String, ResponseEntry)> {
        let mut compiled = Vec::new();
        for (request_path, bucket) in self.redirects_exact {
            let Some(rules) = bucket.as_array() else {
                continue;
            };
            let Some(rule) = rules.iter().min_by_key(|rule| {
                rule.get("order")
                    .and_then(Value::as_i64)
                    .unwrap_or(i64::MAX)
            }) else {
                continue;
            };
            if rule
                .get("action")
                .and_then(Value::as_str)
                .unwrap_or("redirect")
                != "redirect"
            {
                continue;
            }
            let Some(destination) = rule
                .get("destination")
                .and_then(Value::as_str)
                .filter(|destination| !destination.is_empty())
            else {
                continue;
            };
            if rule_is_request_dependent(rule) {
                continue;
            }
            let order = rule
                .get("order")
                .and_then(Value::as_i64)
                .unwrap_or(i64::MAX);
            if self.redirect_matchers.iter().any(|(pattern_order, regex)| {
                *pattern_order < order && regex.is_match(request_path)
            }) {
                continue;
            }
            let status = rule.get("status").and_then(Value::as_u64).unwrap_or(302);
            compiled.push((
                normalized_rule_key(request_path),
                redirect_entry(status, destination),
            ));
        }
        compiled
    }

    /// Whether an ordered REDIRECT can still claim this key, so the serve path
    /// has to walk `\0rules` before answering the compiled entry. Redirects
    /// only: they are the one thing that can move the request off this key,
    /// while the residue's header rules apply to every response anyway.
    ///
    /// Each side is tested against the string the WALK tests it against, and
    /// they differ: the exact map is looked up by the request path with its
    /// trailing slash stripped, while a pattern's regex runs against the raw
    /// request path (rules.php).
    fn claims(&self, key: &str) -> bool {
        self.redirect_exact_keys.contains(&normalized_rule_key(key))
            || self
                .redirect_matchers
                .iter()
                .any(|(_, regex)| regex.is_match(key))
    }

    /// The rules the entries do not carry. Both lists go over whole, in the one
    /// ordered-rule shape the serve path walks — an exact map keyed by path
    /// plus first-segment-bucketed patterns — so `order` still decides between
    /// them and redirects stay first-match-wins.
    fn residue(&self) -> Option<Value> {
        let redirects = !self.redirects_exact.is_empty() || !self.redirects_pattern.is_empty();
        let headers = !self.headers_exact.is_empty() || !self.headers_pattern.is_empty();
        if !redirects && !headers {
            return None;
        }
        let mut residue = Map::new();
        if redirects {
            residue.insert(
                "redirects".into(),
                json!({
                    "exact": self.redirects_exact,
                    "pattern": bucket_pattern_rules(self.redirects_pattern, "source"),
                }),
            );
        }
        if headers {
            residue.insert(
                "headers".into(),
                json!({
                    "exact": self.headers_exact,
                    "pattern": bucket_pattern_rules(self.headers_pattern, "path"),
                }),
            );
        }
        Some(Value::Object(residue))
    }
}

/// `_redirects` keys carry a leading slash and no trailing one; request keys
/// carry a leading slash. Root stays `/`.
fn normalized_rule_key(request_path: &str) -> String {
    let trimmed = request_path.trim_end_matches('/');
    if trimmed.is_empty() {
        "/".into()
    } else {
        trimmed.to_string()
    }
}

fn header_matcher(rule: &Value) -> HeaderMatcher {
    if let Some(regex) = rule
        .get("regex")
        .and_then(Value::as_str)
        .filter(|regex| !regex.is_empty())
    {
        return Regex::new(regex).map_or(HeaderMatcher::Opaque, HeaderMatcher::Pattern);
    }
    rule.get("path")
        .and_then(Value::as_str)
        .map_or(HeaderMatcher::Opaque, |path| {
            HeaderMatcher::Exact(path.to_string())
        })
}

/// One published table file.
#[derive(Debug)]
pub(crate) struct PublishedTables {
    /// `tables` as the root records it: shard key → file name.
    pub tables: BTreeMap<String, String>,
    pub root_file: String,
}

/// Writes the tables, then the root, then the pointer. Nothing reads a table
/// until `root.json` names the root that names it, so a torn publish is
/// invisible rather than half-live.
#[cfg(test)]
pub(crate) fn publish_response_tables(
    stage_root: &Path,
    entries: &BTreeMap<String, ResponseEntry>,
    compiler_version: &str,
) -> Result<PublishedTables> {
    publish_response_tables_with_variants(stage_root, entries, &BTreeMap::new(), compiler_version)
}

/// Writes the base table and any route-specific variants behind one immutable
/// root. A route table is complete, rather than a sparse overlay, so every
/// alias, fallback, lane and header decision is compiled from the variant's
/// actual bytes.
pub(crate) fn publish_response_tables_with_variants(
    stage_root: &Path,
    entries: &BTreeMap<String, ResponseEntry>,
    route_entries: &BTreeMap<String, BTreeMap<String, ResponseEntry>>,
    compiler_version: &str,
) -> Result<PublishedTables> {
    create_dir_all(stage_root)?;
    let tables = write_response_table_set(stage_root, entries)?;
    let mut route_tables = BTreeMap::new();
    for (route_name, entries) in route_entries {
        route_tables.insert(
            route_name.clone(),
            write_response_table_set(stage_root, entries)?,
        );
    }

    let mut root = Map::from_iter([
        ("schema".into(), json!(ARTIFACT_SCHEMA_VERSION)),
        ("compiler".into(), json!(compiler_version)),
        ("tables".into(), json!(tables)),
    ]);
    if !route_tables.is_empty() {
        root.insert("route_tables".into(), json!(route_tables));
    }
    let root = Value::Object(root);
    let root_bytes = format!(
        "<?php\nreturn {};\n",
        crate::finalize::php_export_value(&root)
    );
    let root_file = format!(
        "{VERSION_ROOT_BASENAME}-{}.php",
        &sha256(root_bytes.as_bytes())[..ARTIFACT_HASH_PREFIX_LEN]
    );
    write_bytes(&stage_root.join(&root_file), root_bytes.as_bytes())?;
    write_json(
        &stage_root.join(VERSION_ROOT_POINTER_FILE),
        &json!({ "root": root_file }),
    )?;
    Ok(PublishedTables { tables, root_file })
}

fn write_response_table_set(
    stage_root: &Path,
    entries: &BTreeMap<String, ResponseEntry>,
) -> Result<BTreeMap<String, String>> {
    reject_unservable_headers(entries)?;
    // Every entry is serialized exactly once, whether or not the table splits.
    let rendered: Vec<(&str, String)> = entries
        .iter()
        .map(|(key, entry)| (key.as_str(), render_entry(key, entry)))
        .collect();
    let mut tables = BTreeMap::new();
    if table_length(rendered.iter().map(|(_, body)| body.as_str())) <= RESPONSE_TABLE_SPLIT_BYTES {
        let name = write_table(
            stage_root,
            None,
            &render_table(rendered.iter().map(|(_, body)| body.as_str())),
        )?;
        tables.insert(RESPONSE_TABLE_SINGLE_KEY.to_string(), name);
    } else {
        let mut shards = BTreeMap::<String, Vec<&str>>::new();
        for (key, body) in &rendered {
            shards
                .entry(sha256(key.as_bytes())[..2].to_string())
                .or_default()
                .push(body);
        }
        for (shard, members) in shards {
            let bytes = render_table(members.into_iter());
            let name = write_table(stage_root, Some(&shard), &bytes)?;
            tables.insert(shard, name);
        }
    }

    Ok(tables)
}

/// A response above the PHP-lane ceiling that also needs a header outside the
/// survivor set has no lane that can serve it as compiled. Fail the publish and
/// name the paths — never drop the header silently, because it is usually the
/// one holding a security property.
fn reject_unservable_headers(entries: &BTreeMap<String, ResponseEntry>) -> Result<()> {
    let unservable: Vec<Value> = entries
        .iter()
        .filter_map(|(key, entry)| {
            let dropped = entry.unservable_headers();
            (!dropped.is_empty())
                .then(|| json!({"path": key, "size": entry.length, "headers": dropped}))
        })
        .collect();
    if unservable.is_empty() {
        return Ok(());
    }
    invalid_with_details(
        "response_headers_unservable",
        format!(
            "{} response(s) are above the {PHP_LANE_BODY_MAX_BYTES} byte PHP-lane ceiling and need a header that does not survive X-Accel-Redirect.",
            unservable.len()
        ),
        json!({
            "responses": unservable,
            "limit": PHP_LANE_BODY_MAX_BYTES,
            "survivingHeaders": ACCEL_SURVIVING_HEADERS,
        }),
    )
}

fn write_table(stage_root: &Path, shard: Option<&str>, bytes: &[u8]) -> Result<String> {
    let hash = &sha256(bytes)[..ARTIFACT_HASH_PREFIX_LEN];
    let name = match shard {
        Some(shard) => format!("{RESPONSE_TABLE_BASENAME}-{shard}-{hash}.php"),
        None => format!("{RESPONSE_TABLE_BASENAME}-{hash}.php"),
    };
    let size = bytes.len();
    if size > RESPONSE_TABLE_MAX_BYTES {
        // opcache refuses a file this large, so it would be recompiled on every
        // request forever. This is an assertion, not advice.
        return invalid_with_details(
            "response_table_too_large",
            format!("Response table {name} is {size} bytes, above the {RESPONSE_TABLE_MAX_BYTES} byte limit."),
            json!({
                "shard": shard.unwrap_or(RESPONSE_TABLE_SINGLE_KEY),
                "file": name,
                "size": size,
                "limit": RESPONSE_TABLE_MAX_BYTES,
            }),
        );
    }
    write_bytes(&stage_root.join(&name), bytes)?;
    Ok(name)
}

const TABLE_PROLOGUE: &str = "<?php\nreturn [\n";
const TABLE_EPILOGUE: &str = "];\n";

/// One `key => entry,` line of a table file.
fn render_entry(key: &str, entry: &ResponseEntry) -> String {
    format!(
        "    {} => {},\n",
        crate::finalize::php_key_literal(key),
        crate::finalize::php_export_value_at(&entry.to_value(key), 4)
    )
}

fn table_length<'a>(bodies: impl Iterator<Item = &'a str>) -> usize {
    TABLE_PROLOGUE.len() + TABLE_EPILOGUE.len() + bodies.map(str::len).sum::<usize>()
}

fn render_table<'a>(bodies: impl Iterator<Item = &'a str>) -> Vec<u8> {
    let mut out = String::from(TABLE_PROLOGUE);
    for body in bodies {
        out.push_str(body);
    }
    out.push_str(TABLE_EPILOGUE);
    out.into_bytes()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::finalize::file_meta;

    fn compile(
        entries: &[(&str, &[u8])],
        config: Value,
        redirects_exact: Value,
        redirects_pattern: Value,
        headers_exact: Value,
        headers_pattern: Value,
    ) -> BTreeMap<String, ResponseEntry> {
        compile_for_host(
            entries,
            config,
            redirects_exact,
            redirects_pattern,
            headers_exact,
            headers_pattern,
            false,
        )
    }

    #[allow(clippy::too_many_arguments)]
    fn compile_for_host(
        entries: &[(&str, &[u8])],
        config: Value,
        redirects_exact: Value,
        redirects_pattern: Value,
        headers_exact: Value,
        headers_pattern: Value,
        noindex_host: bool,
    ) -> BTreeMap<String, ResponseEntry> {
        let files: BTreeMap<String, FileMeta> = entries
            .iter()
            .map(|(path, bytes)| ((*path).to_string(), file_meta(path, bytes, None)))
            .collect();
        let private = BTreeSet::new();
        let listings = Vec::new();
        let zero = Map::new();
        compile_response_table(&ResponseCompileInput {
            files: &files,
            private: &private,
            serving_config: config.as_object().unwrap(),
            redirects_exact: redirects_exact.as_object().unwrap(),
            redirects_pattern: redirects_pattern.as_array().unwrap(),
            headers_exact: headers_exact.as_object().unwrap(),
            headers_pattern: headers_pattern.as_array().unwrap(),
            listings: &listings,
            zero_actions: &zero,
            robots_blob: Some((
                sha256(DENY_ALL_ROBOTS.as_bytes()),
                DENY_ALL_ROBOTS.len() as u64,
            )),
            noindex_host,
        })
    }

    /// The `\0rules` residue, or the section of it a test cares about.
    fn residue(table: &BTreeMap<String, ResponseEntry>, section: &str) -> Value {
        table[RESPONSE_KEY_RULES].action.clone().expect("residue")[section].clone()
    }

    fn assets(config: Value) -> BTreeMap<String, ResponseEntry> {
        compile(
            &[
                ("index.html", b"<h1>home</h1>"),
                ("archive.zip", b"zipbytes"),
                ("styles.css", b"body{color:red}"),
                ("app.3f9c2d1a.js", b"console.log(1)"),
                (
                    "_spacefast/platform/27ae1ac06e3ed999/preact.js",
                    b"export{};",
                ),
                ("404.html", b"<h1>gone</h1>"),
            ],
            config,
            json!({}),
            json!([]),
            json!({}),
            json!([]),
        )
    }

    /// The whole functions-route reversal of the inert-`.php` rule, at the
    /// compile seam: a routable `functions/<name>.php` becomes a `t=php` action
    /// at its derived key with its source hidden, while a pattern-named file
    /// and a stray upload keep the inert attachment entry.
    #[test]
    fn functions_route_php_compiles_to_a_php_action_and_hides_its_source() {
        let handler: &[u8] = b"<?php sf_json(['ok' => true]);\n";
        let table = compile(
            &[
                ("index.html", b"<h1>home</h1>"),
                ("functions/hello.php", handler),
                ("functions/api/index.php", b"<?php sf_json([]);\n"),
                (
                    "functions/[id].php",
                    b"<?php // pattern: not routable here\n",
                ),
                ("uploaded.php", b"<?php echo 'stray';\n"),
            ],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([]),
            json!({}),
            json!([]),
        );

        let hello = &table["/hello"];
        let action = hello.action.as_ref().expect("php action");
        assert_eq!(action["t"], json!(RESPONSE_ACTION_PHP));
        assert_eq!(action["src"], json!("functions/hello.php"));
        assert_eq!(action["sha"], json!(sha256(handler)));
        assert_eq!(hello.blob, None);
        assert_eq!(hello.status, 200);
        // `index` drops out of the route exactly as in the JS file router.
        assert_eq!(
            table["/api"].action.as_ref().expect("index route")["t"],
            json!(RESPONSE_ACTION_PHP)
        );

        // The handler source never serves — same 404 action as a private path.
        let source = &table["/functions/hello.php"];
        assert_eq!(source.status, 404);
        assert_eq!(
            source.action.as_ref().expect("hidden source")["t"],
            json!(RESPONSE_ACTION_NOT_FOUND)
        );

        // Everything that is NOT a routable handler keeps the inert attachment.
        for key in ["/functions/[id].php", "/uploaded.php"] {
            let inert = &table[key];
            assert!(inert.action.is_none(), "{key} must stay inert");
            assert_eq!(
                inert.headers.get("content-type").map(String::as_str),
                Some("text/plain; charset=utf-8")
            );
            assert_eq!(
                inert.headers.get("content-disposition").map(String::as_str),
                Some("attachment")
            );
        }
    }

    /// The complete lane rule and the `xa` flag that carries the part of
    /// it the compiler cannot decide.
    #[test]
    fn the_lane_follows_the_headers_the_client_url_and_the_policy() {
        let table = assets(
            json!({"index": "index.html", "clean_urls": false, "fallback": {"path": "index.html", "status": 200}}),
        );

        // Survivor-only headers at an extension the provider leaves alone.
        let archive = &table["/archive.zip"];
        assert_eq!(archive.lane("/archive.zip"), RESPONSE_LANE_ACCEL);
        assert_eq!(archive.cache_class, Some(CACHE_CLASS_REVALIDATE));
        assert!(archive
            .to_value("/archive.zip")
            .get(RESPONSE_ENTRY_ALLOWLISTED_EXT)
            .is_none());

        // Same headers, but the provider would overwrite the must-revalidate
        // policy with public ten-year at a `.css` client URL.
        let stylesheet = &table["/styles.css"];
        assert_eq!(stylesheet.lane("/styles.css"), RESPONSE_LANE_PHP);
        assert_eq!(
            stylesheet.to_value("/styles.css")[RESPONSE_ENTRY_ALLOWLISTED_EXT],
            json!(1)
        );

        // ...unless public-immutable IS the intended policy, which a hashed
        // name earns. Then the provider's rewrite agrees and accel is honest.
        let hashed = &table["/app.3f9c2d1a.js"];
        assert_eq!(hashed.cache_class, Some(CACHE_CLASS_IMMUTABLE));
        assert_eq!(hashed.lane("/app.3f9c2d1a.js"), RESPONSE_LANE_ACCEL);
        assert_eq!(
            hashed.to_value("/app.3f9c2d1a.js")[RESPONSE_ENTRY_ALLOWLISTED_EXT],
            json!(1),
            "a protected space still has to force this one onto the PHP lane"
        );

        // Every HTML page, always.
        let page = &table["/index.html"];
        assert_eq!(page.lane("/index.html"), RESPONSE_LANE_PHP);
        assert_eq!(page.cache_class, Some(CACHE_CLASS_HTML));
        assert_eq!(table["/"].blob, page.blob);

        // A non-200 has no local file to hand nginx, and the compiled specials
        // answer at a client URL the compiler cannot see.
        assert_eq!(
            table[RESPONSE_KEY_NOT_FOUND].lane(RESPONSE_KEY_NOT_FOUND),
            RESPONSE_LANE_PHP
        );
        let spa = &table[RESPONSE_KEY_SPA];
        assert_eq!(spa.lane(RESPONSE_KEY_SPA), RESPONSE_LANE_PHP);
        assert_eq!(
            spa.to_value(RESPONSE_KEY_SPA)[RESPONSE_ENTRY_ALLOWLISTED_EXT],
            json!(1)
        );
    }

    /// The validator kind follows the lane.
    #[test]
    fn each_lane_carries_the_validator_it_can_actually_send() {
        let table = assets(json!({"index": "index.html", "clean_urls": false}));
        let meta = file_meta("archive.zip", b"zipbytes", None);
        assert_eq!(
            table["/archive.zip"].to_value("/archive.zip")[RESPONSE_ENTRY_ETAG],
            json!(format!("{:x}-{:x}", meta.mtime, meta.size))
        );
        // The Zero platform build. Its directory is the digest of its bytes, so
        // the URL is as frozen as a hashed filename even though the hash is a
        // path segment — `immutable_path` is what recognises it, and the same
        // immutable class is what keeps it on accel instead of PHP.
        let platform = &table["/_spacefast/platform/27ae1ac06e3ed999/preact.js"];
        assert_eq!(platform.cache_class, Some(CACHE_CLASS_IMMUTABLE));
        assert_eq!(
            platform.lane("/_spacefast/platform/27ae1ac06e3ed999/preact.js"),
            RESPONSE_LANE_ACCEL
        );

        let page = file_meta("index.html", b"<h1>home</h1>", None);
        assert_eq!(
            table["/index.html"].to_value("/index.html")[RESPONSE_ENTRY_ETAG],
            json!(page.sha256)
        );
    }

    #[test]
    fn only_a_real_content_hash_freezes_a_url() {
        for frozen in [
            "/app.3f9c2d1a.js",
            "/styles.abc123def456.css",
            "/chunk-XK2FA9.css",
            "/assets/index-DiwrgTda.js",
        ] {
            assert!(carries_content_hash(frozen), "{frozen}");
        }
        for kept in [
            "/photo_20240115.jpg",
            "/hero-image1.jpg",
            "/jquery-3.7.1.min.js",
            "/logo-2024.png",
            "/styles.css",
            "/README.md",
        ] {
            assert!(!carries_content_hash(kept), "{kept}");
        }
    }

    /// A theme change swaps the blob behind the stylesheet URL, so a publisher
    /// rule must not pin it. Everywhere else a publisher `cache-control` rule
    /// wins, which is what withholding the class says.
    #[test]
    fn a_publisher_cache_control_rule_wins_everywhere_except_the_theme_stylesheet() {
        let pin = |path: &str| {
            json!({path: [{
                "path": path,
                "regex": format!("^{}$", path.replace('.', "\\.")),
                "operations": [{"kind": "set", "name": "Cache-Control", "value": "public, max-age=31536000, immutable"}],
                "order": 0
            }]})
        };
        let mut headers = pin(THEME_STYLESHEET_URL);
        headers
            .as_object_mut()
            .unwrap()
            .append(pin("/styles.css").as_object_mut().unwrap());
        let table = compile(
            &[
                ("index.html", b"<h1>home</h1>"),
                ("styles.css", b"body{color:red}"),
                ("__spacefast_generated/theme.css", b":root{--a:1}"),
            ],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([]),
            headers,
            json!([]),
        );
        let theme = &table[THEME_STYLESHEET_URL];
        assert_eq!(theme.cache_class, Some(CACHE_CLASS_REVALIDATE));
        assert!(!theme.headers.contains_key("cache-control"));
        assert_eq!(table["/styles.css"].cache_class, None);
    }

    /// robots.txt controls crawling, the header controls indexing.
    #[test]
    fn a_noindex_host_marks_its_pages_and_a_publisher_cannot_unmark_them() {
        let table = compile_for_host(
            &[("index.html", b"<h1>home</h1>"), ("logo.png", b"bytes")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([]),
            json!({"/index.html": [{
                "path": "/index.html",
                "operations": [{"kind": "remove", "name": "X-Robots-Tag"}],
                "order": 0
            }]}),
            json!([]),
            true,
        );
        let page = &table["/index.html"];
        assert_eq!(
            page.headers.get("x-robots-tag").map(String::as_str),
            Some("noindex, nofollow")
        );
        assert_eq!(page.lane("/index.html"), RESPONSE_LANE_PHP);
        // A large binary has no signal to carry; it is not silently tagged.
        assert!(!table["/logo.png"].headers.contains_key("x-robots-tag"));
    }

    /// A `_headers` rule ships WHOLE in the residue and nothing of it lands in
    /// an entry; the compiler resolves the NAMES the lane gate needs.
    #[test]
    fn a_headers_rule_ships_whole_and_still_moves_its_target_off_the_accel_lane() {
        let table = compile(
            &[("archive.zip", b"zipbytes"), ("assets/app.js", b"js")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([]),
            json!({"/archive.zip": [{
                "path": "/archive.zip",
                "regex": "^/archive\\.zip$",
                "operations": [{"kind": "set", "name": "X-Frame-Options", "value": "DENY"}],
                "order": 0
            }]}),
            json!([{
                "path": "/assets/*",
                "regex": "^/assets/(?<file>[^/]+)$",
                "operations": [{"kind": "set", "name": "X-Asset", "value": "name=:file"}],
                "order": 1
            }]),
        );

        let archive = &table["/archive.zip"];
        assert!(
            !archive.headers.contains_key("x-frame-options"),
            "a publisher header is never compiled into an entry"
        );
        assert!(archive.rule_header_names.contains("x-frame-options"));
        assert_eq!(archive.lane("/archive.zip"), RESPONSE_LANE_PHP);
        assert_eq!(
            archive.unservable_headers(),
            Vec::<&str>::new(),
            "under the ceiling either lane can still be chosen"
        );

        // The template survives verbatim. Baking wrote the literal `name=:file`
        // onto the response; the residue keeps it a template for PHP to expand.
        let asset = &table["/assets/app.js"];
        assert!(!asset.headers.contains_key("x-asset"));
        assert!(asset.rule_header_names.contains("x-asset"));
        let headers = residue(&table, "headers");
        assert_eq!(
            headers["exact"]["/archive.zip"][0]["operations"][0]["value"],
            json!("DENY")
        );
        assert_eq!(
            headers["pattern"]["by_first_segment"]["assets"][0]["operations"][0]["value"],
            json!("name=:file")
        );
    }

    /// Above the PHP-lane ceiling neither lane can serve the intended response,
    /// so the publish fails by name instead of dropping the header. The intended
    /// set is the compiled headers PLUS the names the residue could add.
    #[test]
    fn an_oversized_body_that_needs_a_dropped_header_fails_the_publish() {
        let temp = tempfile::tempdir().unwrap();
        let mut headers = BTreeMap::new();
        headers.insert("content-type".into(), "video/mp4".into());
        let servable = ResponseEntry::with_body(
            200,
            headers,
            sha256(b"huge"),
            PHP_LANE_BODY_MAX_BYTES + 1,
            CACHE_CLASS_REVALIDATE,
        );
        let mut entries = BTreeMap::from([("/big.mp4".to_string(), servable.clone())]);
        // A survivor-only header set is fine at any size: the accel lane sends
        // it whole.
        publish_response_tables(temp.path(), &entries, "0.0.0-test").unwrap();

        entries.insert(
            "/guarded.mp4".to_string(),
            ResponseEntry {
                rule_header_names: BTreeSet::from(["x-frame-options".to_string()]),
                ..servable
            },
        );
        match publish_response_tables(temp.path(), &entries, "0.0.0-test") {
            Err(crate::finalize::FinalizeError::Invalid { code, details, .. }) => {
                assert_eq!(code, "response_headers_unservable");
                let details = details.expect("the publisher needs the paths");
                assert_eq!(details["responses"][0]["path"], json!("/guarded.mp4"));
                assert_eq!(
                    details["responses"][0]["headers"],
                    json!(["x-frame-options"])
                );
            }
            other => panic!("expected a rejected publish, got {other:?}"),
        }
    }

    #[test]
    fn an_earlier_pattern_rule_keeps_an_exact_redirect_out_of_the_table() {
        let table = compile(
            &[("index.html", b"home")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({"/docs/intro": [{"action":"redirect","destination":"/start","status":301,"order":1}]}),
            json!([{
                "source": "/docs/:slug",
                "regex": "^/docs/(?P<slug>[^/]+)/?$",
                "action": "redirect",
                "destination": "/guides/:slug",
                "status": 301,
                "order": 0
            }]),
            json!({}),
            json!([]),
        );
        // The ordered walk owns this path, so nothing precomputed may answer it.
        assert!(!table.contains_key("/docs/intro"));
        // ...and the complete ordered list is published for that walk.
        assert!(table.contains_key(RESPONSE_KEY_RULES));
    }

    /// A version is not reachable until `root.json` names a root that names the
    /// tables, so the pointer is written last and a torn publish is invisible.
    #[test]
    fn tables_split_by_size_and_the_pointer_is_written_last() {
        let temp = tempfile::tempdir().unwrap();
        let stage = temp.path();
        let small = BTreeMap::from([(
            "/index.html".to_string(),
            ResponseEntry::bodyless(200, BTreeMap::new()),
        )]);
        let published = publish_response_tables(stage, &small, "0.0.0-test").unwrap();
        assert_eq!(published.tables.len(), 1);
        assert!(published.tables.contains_key(RESPONSE_TABLE_SINGLE_KEY));
        let root: Value =
            serde_json::from_slice(&std::fs::read(stage.join(VERSION_ROOT_POINTER_FILE)).unwrap())
                .unwrap();
        assert_eq!(root["root"], json!(published.root_file));
        let root_source = std::fs::read_to_string(stage.join(&published.root_file)).unwrap();
        assert!(root_source.contains("'schema' => 4"));
        assert!(root_source.contains(published.tables[RESPONSE_TABLE_SINGLE_KEY].as_str()));

        // Past the split threshold the same entries shard by sha256(path)[0:2].
        let mut large = BTreeMap::new();
        for index in 0..4_000 {
            let mut headers = BTreeMap::new();
            headers.insert("content-type".into(), "text/plain; charset=utf-8".into());
            large.insert(
                format!("/generated/path-{index:06}.txt"),
                ResponseEntry::bodyless(200, headers),
            );
        }
        let sharded = publish_response_tables(stage, &large, "0.0.0-test").unwrap();
        assert!(sharded.tables.len() > 1);
        assert!(!sharded.tables.contains_key(RESPONSE_TABLE_SINGLE_KEY));
        for (shard, name) in &sharded.tables {
            assert!(name.starts_with(&format!("{RESPONSE_TABLE_BASENAME}-{shard}-")));
            assert!(stage.join(name).is_file());
        }
        // Every key landed in the shard its hash addresses.
        for key in large.keys() {
            assert!(sharded.tables.contains_key(&sha256(key.as_bytes())[..2]));
        }
    }

    /// opcache refuses a file above 1 MiB, so finalize fails instead.
    #[test]
    fn a_table_file_above_the_hard_cap_fails_the_publish() {
        let temp = tempfile::tempdir().unwrap();
        let mut headers = BTreeMap::new();
        headers.insert("content-type".into(), "text/plain".into());
        // Sharding cannot rescue this: keys shard by `sha256(key)[0:2]`, so a
        // single entry over the cap is a single shard over the cap. Padding
        // many keys instead would only prove that 256 shards divide the bytes.
        headers.insert("x-padding".into(), "p".repeat(RESPONSE_TABLE_MAX_BYTES + 1));
        let entries = BTreeMap::from([("/pad".to_string(), ResponseEntry::bodyless(200, headers))]);
        let error = publish_response_tables(temp.path(), &entries, "0.0.0-test");
        match error {
            Err(crate::finalize::FinalizeError::Invalid { code, .. }) => {
                assert_eq!(code, "response_table_too_large")
            }
            other => panic!("expected a rejected publish, got {other:?}"),
        }
    }

    /// Every emitted key an ordered rule can claim carries `r`, whatever kind of
    /// entry answers there.
    #[test]
    fn every_key_an_ordered_rule_can_claim_is_marked_rules_first() {
        let table = compile(
            &[("docs/index.html", b"docs"), ("guide.html", b"guide")],
            json!({"index": "index.html", "clean_urls": true}),
            json!({}),
            json!([{
                "source": "/*",
                "regex": "^/(?<path>.*)$",
                "action": "rewrite",
                "destination": "/ai/:path",
                "conditions": {"agent": "ai"},
                "order": 0
            }]),
            json!({}),
            json!([]),
        );
        for key in ["/docs/index.html", "/docs/", "/docs", "/guide", "/guide/"] {
            assert!(table[key].rules_first, "{key} must run the rules first");
        }
        assert_eq!(table["/docs"].status, 308);
        assert_eq!(
            table["/docs"].headers.get("location").map(String::as_str),
            Some("/docs/")
        );

        // An EXACT rule reaches the slashed twin too: the walk matches rules
        // against the request path with its trailing slash stripped, so a rule
        // written for `/docs` is the rule a visitor at `/docs/` gets — and
        // `/docs/` is where this table's own canonical 308 sends them.
        let exact = compile(
            &[("docs/index.html", b"docs")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({"/docs": [{
                "destination": "/docs.md",
                "status": 200,
                "action": "rewrite",
                "force": true,
                "conditions": [{"kind": "agent", "values": ["true"]}],
                "order": 0
            }]}),
            json!([]),
            json!({}),
            json!([]),
        );
        assert!(
            exact["/docs/"].rules_first,
            "/docs/ must run the rules first"
        );
        assert!(exact["/docs"].rules_first, "/docs must run the rules first");

        // ...and a key no rule can reach still answers straight from the table.
        let plain = compile(
            &[("docs/index.html", b"docs")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([{
                "source": "/blog/:slug",
                "regex": "^/blog/(?<slug>[^/]+)$",
                "action": "redirect",
                "destination": "/posts/:slug",
                "order": 0
            }]),
            json!({}),
            json!([]),
        );
        assert!(!plain["/docs/"].rules_first);
        assert!(!plain["/docs"].rules_first);
    }

    /// The deny-all blob is compiled for every version and occupies its own
    /// `\0` key, so a published `robots.txt` keeps the ordinary one.
    #[test]
    fn the_platform_robots_entry_never_takes_the_published_robots_key() {
        let table = compile(
            &[("index.html", b"home"), ("robots.txt", b"User-agent: *\n")],
            json!({"index": "index.html", "clean_urls": false}),
            json!({}),
            json!([]),
            json!({}),
            json!([]),
        );
        let published = &table["/robots.txt"];
        assert_eq!(
            published.blob,
            Some(file_meta("robots.txt", b"User-agent: *\n", None).sha256)
        );
        assert_eq!(
            table[RESPONSE_KEY_ROBOTS].blob,
            Some(sha256(DENY_ALL_ROBOTS.as_bytes()))
        );
    }
}
