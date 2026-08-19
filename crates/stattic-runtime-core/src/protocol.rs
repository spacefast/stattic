//! Finalizer protocol limits and markers shared across config, transform, and
//! policy modules, plus the generated TypeScript/PHP protocol sources emitted
//! by `cargo run -p stattic-runtime-compiler --bin protocol-codegen`.

use serde::Serialize;
use serde_json::Value;

use crate::egress::{
    EgressProfile, DENIED_IPV4_NETWORKS, DENIED_IPV6_NETWORKS, EGRESS_MAX_REDIRECT_HOPS,
    SERVING_INTERNAL_HOSTS,
};

pub const CONFIG_FILE_MAX_BYTES: usize = 256 * 1024;
pub const CONFIG_OVERLAY_MAX_BYTES: usize = 16 * 1024;
pub const CONFIG_TEMPLATE_LIMIT: usize = 100;
/// File-owned space display name (`sf.jsonc#name`). Matches the stored
/// `spaces.name` column so a config-declared name is always storable.
pub const CONFIG_SPACE_NAME_MAX_CHARS: usize = 255;
pub const CONFIG_ACCESS_PUBLIC_PATTERN_LIMIT: usize = 100;
/// Max `crons` entries. Scheduled work is a provider crontab slot per entry,
/// so the cap is deliberately small and hard.
pub const CONFIG_CRON_LIMIT: usize = 8;
pub const CONFIG_CRON_PATH_MAX_CHARS: usize = 512;
/// Generated runtime file the serving engine reads to answer a scheduled
/// request. Written only when a version declares at least one cron.
pub const CRONS_ARTIFACT_PATH: &str = "__spacefast/crons.json";
pub const CRONS_ARTIFACT_VERSION: u32 = 1;
pub const CONFIG_ACCESS_RESOURCE_PATTERN_MAX_CHARS: usize = 1_024;
pub const CONFIG_META_TITLE_MAX_CHARS: usize = 300;
pub const CONFIG_META_DESCRIPTION_MAX_CHARS: usize = 1_000;
/// Browser-facing metadata asset paths/URLs need ample room for signed URLs;
/// 2,000 remains below common request-line limits and covers OG plus favicons.
pub const CONFIG_META_IMAGE_MAX_CHARS: usize = 2_000;
pub const CONFIG_INJECT_SNIPPET_MAX_BYTES: usize = 8 * 1024;
pub const CONFIG_INJECT_SNIPPET_LIMIT: usize = 16;
pub const CONFIG_PAGES_COLOR_MAX_CHARS: usize = 64;
pub const CONFIG_PAGES_LOGO_MAX_CHARS: usize = 2_000;
pub const CONFIG_PAGES_NAME_MAX_CHARS: usize = 120;
pub const CONFIG_PAGES_FONT_FAMILY_MAX_CHARS: usize = 256;
pub const CONFIG_BUILD_TIMEOUT_MIN_SECONDS: u64 = 60;
pub const CONFIG_BUILD_TIMEOUT_MAX_SECONDS: u64 = 7_200;
pub const CONFIG_FALLBACK_STATUS_MIN: u64 = 100;
pub const CONFIG_FALLBACK_STATUS_MAX: u64 = 599;
pub const CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS: usize = 64;
pub const CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS: usize = 128;
pub const CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS: usize = 160;
pub const CONFIG_SUPERPOWERS_OVERRIDE_LIMIT: usize = 128;
pub const CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS: usize = 500;
pub const CONFIG_CANONICAL_FILE: &str = "sf.jsonc";
pub const CONFIG_ALIAS_FILES: &[&str] = &[
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];
pub const CONFIG_ACCEPTED_FILES: &[&str] = &[
    CONFIG_CANONICAL_FILE,
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];

pub const TEMPLATE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const TEMPLATE_VARIANT_FILE_LIMIT: usize = 100;
pub const TEMPLATE_VARIANT_ROUTE_LIMIT: usize = 8;
pub const TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS: usize = 128;
pub const PAGE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const GATE_THEME_FILE_MAX_BYTES: usize = 64 * 1024;
pub const GATE_THEME_VARS_MAX_BYTES: usize = 8 * 1024;
pub const SPACE_THEME_CSS_MAX_BYTES: usize = 16 * 1024;
pub const SPACE_THEME_PRESET_LIMIT: usize = 64;
pub const SPACE_THEME_SLUG_MAX_CHARS: usize = 64;
pub const SPACE_THEME_VALUE_MAX_CHARS: usize = 128;
pub const SPACE_THEME_NAME_MAX_CHARS: usize = 128;
pub const SPACE_THEME_SIZE_MAX_CHARS: usize = 64;
pub const SPACE_THEME_FONT_FAMILY_MAX_CHARS: usize = 256;
pub const THEME_JSON_FONT_FAMILY_LIMIT: usize = 32;

pub const ZERO_BUNDLE_MAX_BYTES: usize = 786_432;
pub const ZERO_BUNDLE_LIMIT: usize = 8;
pub const ZERO_STATIC_FILE_LIMIT: usize = 16;
pub const ZERO_ENTRY_LIMIT: usize = 128;
pub const ZERO_SOURCE_MAX_BYTES: usize = 2 * 1024 * 1024;
pub const ZERO_ID_MAX_CHARS: usize = 256;
pub const ZERO_ROUTE_PATH_MAX_CHARS: usize = 2_048;
pub const ZERO_ENTRY_NAME_MAX_CHARS: usize = 128;
pub const ZERO_CONTENT_TYPE_MAX_CHARS: usize = 255;

pub const EXECUTION_TIMEOUT_MS_DEFAULT: u64 = 5_000;
/// Cloudflare's 30-second per-invocation CPU ceiling keeps portable routes
/// within the execution time available there.
pub const EXECUTION_TIMEOUT_MS_MAX: u64 = 30_000;
pub const EXECUTION_MEMORY_BYTES_DEFAULT: usize = 32 * 1024 * 1024;
/// Cloudflare Workers' 128 MB memory limit anchors portable route memory.
pub const EXECUTION_MEMORY_BYTES_MAX: usize = 128 * 1024 * 1024;
pub const EXECUTION_BODY_BYTES_DEFAULT: usize = 10 * 1024 * 1024;
/// Request and response payload caps stay symmetric at 10 MiB so either side
/// of a broker exchange fits the same bounded transport budget.
pub const EXECUTION_BODY_BYTES_MAX: usize = 10 * 1024 * 1024;
pub const EXECUTION_OUTPUT_BYTES_DEFAULT: usize = 10 * 1024 * 1024;
/// Request and response payload caps stay symmetric at 10 MiB so either side
/// of a broker exchange fits the same bounded transport budget.
pub const EXECUTION_OUTPUT_BYTES_MAX: usize = 10 * 1024 * 1024;
pub const EXECUTION_SUBREQUESTS_DEFAULT: usize = 10;
/// The local tier brokers every subrequest through a PHP-FPM worker, whose
/// practical per-invocation ceiling is 50.
pub const EXECUTION_SUBREQUESTS_MAX: usize = 50;
pub const EXECUTION_DB_OPERATIONS_DEFAULT: usize = 64;
/// Store operations batch at most 64 statements, so 256 operations bounds a
/// single execution to four such batches.
pub const EXECUTION_DB_OPERATIONS_MAX: usize = 256;

pub const PAGE_PROTOCOL_FORMAT: &str = "spacefast.page-protocol.v1";
pub const BROKER_PROTOCOL: &str = "spacefast.broker.v1";

/// The artifact schema the compiler emits. The engine accepts a *range*
/// (`ARTIFACT_SCHEMA_MIN..=ARTIFACT_SCHEMA_MAX`) so a fleet is never taken down
/// by an equality check; today the range is a single point.
pub const ARTIFACT_SCHEMA_VERSION: u64 = 4;
pub const ARTIFACT_SCHEMA_MIN: u64 = 4;
pub const ARTIFACT_SCHEMA_MAX: u64 = 4;
pub const ARTIFACT_SCHEMA_NAME: &str = "static-runtime-v4";

/// Hex characters of `sha256(file bytes)` that name a content-addressed PHP
/// artifact (`<base>-<h16>.php`).
pub const ARTIFACT_HASH_PREFIX_LEN: usize = 16;
pub const VERSION_ROOT_POINTER_FILE: &str = "root.json";
pub const VERSION_ROOT_BASENAME: &str = "root";
pub const RESPONSE_TABLE_BASENAME: &str = "responses";
/// The `tables` key a single unsharded response table is published under.
pub const RESPONSE_TABLE_SINGLE_KEY: &str = "*";

/// One table by default; shard by `sha256(path)[0:2]` once the single file
/// passes this size. Finalize fails outright above the hard cap,
/// which is `opcache.max_file_size` on wp.cloud.
pub const RESPONSE_TABLE_SPLIT_BYTES: usize = 512 * 1024;
pub const RESPONSE_TABLE_MAX_BYTES: usize = 1024 * 1024;

// Entry keys. Short, because they are repeated once per
// compiled path in an opcached array.
pub const RESPONSE_ENTRY_STATUS: &str = "s";
pub const RESPONSE_ENTRY_HEADERS: &str = "h";
pub const RESPONSE_ENTRY_BLOB: &str = "b";
/// The precomputed validator. Its KIND follows the compiled lane: the
/// nginx `<hexmtime>-<hexsize>` value on the accel lane, the sha256 on the PHP
/// lane. One key, because an entry has exactly one compiled lane. Both kinds are
/// stored UNQUOTED — the serve path is the single owner of the `ETag` header's
/// quoting, so no shape can reach a visitor double-quoted.
pub const RESPONSE_ENTRY_ETAG: &str = "et";
pub const RESPONSE_ENTRY_LENGTH: &str = "len";
pub const RESPONSE_ENTRY_LANE: &str = "lane";
pub const RESPONSE_ENTRY_ACTION: &str = "a";
/// Set to 1 when the CLIENT URL's extension is in [`PROVIDER_ASSET_EXTENSIONS`].
/// The serve path forces the PHP lane on such an entry when the space is
/// protected: the provider rewrites every accel'd body
/// at those extensions to `max-age=315360000` + `Access-Control-Allow-Origin: *`
/// and lets the edge keep it, which would publish private bytes.
pub const RESPONSE_ENTRY_ALLOWLISTED_EXT: &str = "xa";
/// Cache class marker (see `CACHE_CLASS_*`). Absent when `h` already carries an
/// explicit publisher `cache-control`.
pub const RESPONSE_ENTRY_CACHE_CLASS: &str = "cc";
/// Set to 1 when an ordered rule (a redirect the compiler could not reduce to
/// an exact entry, or a host-conditional `_headers` rule) can still claim this
/// path. The serve path evaluates `\0rules` before answering such an entry.
pub const RESPONSE_ENTRY_RULES_FIRST: &str = "r";

pub const RESPONSE_LANE_ACCEL: u64 = 0;
pub const RESPONSE_LANE_PHP: u64 = 1;

/// `cc` values. The overlay's `open` flag picks the open or protected string at
/// send time, because open-ness is a space property and the version tables are
/// immutable. The composing PHP is `_sf_cache_control`
/// (runtime/engine/runtime/serve-fast.php) — these doc strings mirror it, they
/// do not define it.
///
/// - `imm` — open: `public, max-age=31536000, immutable`. Protected: NEVER
///   immutable (a cached year outlives every revocation), so it composes
///   down to `private, no-store`.
/// - `html` — open: `public, max-age=0, must-revalidate`, paired with the
///   `A8C-Edge-Cache: cache` opt-in the engine adds at send time. Deliberately
///   NO `s-maxage`: a document URL changes when its live route moves, and
///   correctness must not depend on every Accept-Encoding cache variant being
///   evicted — provider purges accelerate convergence, revalidation guarantees
///   it. Protected: `private, no-store`.
/// - `no` — open: `public, max-age=0, must-revalidate`. Protected:
///   `private, no-store`.
pub const CACHE_CLASS_IMMUTABLE: &str = "imm";
pub const CACHE_CLASS_HTML: &str = "html";
pub const CACHE_CLASS_REVALIDATE: &str = "no";

/// `\0`-prefixed keys are impossible request paths (NUL is rejected at
/// normalize), so a compiled special never collides with a published path.
pub const RESPONSE_KEY_SPA: &str = "\0spa";
pub const RESPONSE_KEY_NOT_FOUND: &str = "\x00404";
pub const RESPONSE_KEY_NOT_FOUND_PREFIX: &str = "\x00404:";
pub const RESPONSE_KEY_RULES: &str = "\0rules";
pub const RESPONSE_KEY_ROBOTS: &str = "\0robots";

/// Action discriminators carried in `a.t`.
pub const RESPONSE_ACTION_ZERO: &str = "zero";
pub const RESPONSE_ACTION_FUNCTION: &str = "fx";
/// A committed `functions/<route>.php` executed in the hardened engine worker
/// (runtime/engine/runtime/php-functions.php). Only a functions-route `.php`
/// compiles to this action; every other `.php` stays inert (forced
/// `text/plain` + `content-disposition: attachment`).
pub const RESPONSE_ACTION_PHP: &str = "php";
pub const RESPONSE_ACTION_PROXY: &str = "proxy";
pub const RESPONSE_ACTION_LISTING: &str = "listing";
pub const RESPONSE_ACTION_NOT_FOUND: &str = "404";

/// Everything nginx keeps across an `X-Accel-Redirect` (measured live,
/// 2026-08-07). An entry whose intended header set stays inside this list
/// can ride the accel lane; anything else needs the PHP body lane.
///
/// Two headers that looked like members are not. `content-language` was
/// live-refuted — it does not come back on the wire, so an entry carrying one
/// takes the PHP lane. `accept-ranges` is generated by nginx for the file it
/// serves rather than passed through, so it is not something a compiled entry
/// can rely on surviving.
pub const ACCEL_SURVIVING_HEADERS: &[&str] = &[
    "cache-control",
    "content-disposition",
    "content-type",
    "expires",
    "set-cookie",
];

/// Response headers only the platform may set.
///
/// `A8C-Edge-Cache` is the edge's cache opt-in: the engine adds it to every
/// public PHP-lane response, and `A8C-Edge-Cache: no-cache` forces a bypass
/// where correctness demands one. `x-ac`/`x-sc`/`x-nc` are the edge's own cache
/// telemetry. A publisher `_headers` rule that set any of them would either
/// publish private bytes to the shared edge or forge a cache verdict, so
/// finalize drops those operations and the engine refuses them at send time.
pub const PLATFORM_OWNED_HEADER_PREFIXES: &[&str] = &["a8c-"];
pub const PLATFORM_OWNED_HEADERS: &[&str] = &["x-ac", "x-nc", "x-sc"];

/// The extensions the provider rewrites on an nginx-served response (measured
/// live, 2026-08-07): it replaces the cache policy with
/// `max-age=315360000`, adds `Access-Control-Allow-Origin: *`, and lets the edge
/// cache the bytes.
///
/// The gate reads the CLIENT URL extension, never the stored blob name — the
/// probe proved an extensionless accel target served at a `.css` client URL is
/// still rewritten.
pub const PROVIDER_ASSET_EXTENSIONS: &[&str] = &[
    "avif", "css", "eot", "gif", "jpeg", "jpg", "js", "mp3", "mp4", "otf", "pdf", "png", "svg",
    "ttf", "webm", "webp", "woff", "woff2",
];

/// The largest body finalize will commit to the PHP lane.
///
/// nginx buffers the whole FastCGI response before it writes a byte to the
/// client, so a slow reader never holds a worker and the real bound is origin
/// disk, not worker occupancy. This is a pinned honest ceiling rather than a
/// measured one: above it, a response that also needs a header outside
/// [`ACCEL_SURVIVING_HEADERS`] cannot be served as intended in either lane, and
/// finalize fails with `response_headers_unservable` instead of silently
/// dropping the header.
pub const PHP_LANE_BODY_MAX_BYTES: u64 = 100 * 1024 * 1024;

/// The compiled theme stylesheet. One generated stylesheet carries every
/// theme value; pages link it at a STABLE client URL and are never rewritten, so
/// a theme change recompiles one blob, repoints the overlay, and purges one URL.
/// Its entry therefore must revalidate — it is the one URL whose bytes move
/// underneath it.
pub const THEME_STYLESHEET_PATH: &str = "__spacefast_generated/theme.css";
pub const THEME_STYLESHEET_URL: &str = "/__spacefast_generated/theme.css";

pub const CHALLENGE_MARKER: &str = "<!--spacefast:slot:challenge:v1-->";
pub const DENY_MARKER: &str = "<!--spacefast:slot:deny:v1-->";
pub const LAYOUT_MARKER: &str = "<!--spacefast:slot:layout-content:v1-->";
pub const BADGE_MARKER: &str = "<!--spacefast:slot:badge:v1-->";
/// Where a compiled listing shell takes its joined rows. A public listing is
/// joined once at finalize; a protected one is joined per request from the
/// admitted row fragments, so both sides need the same anchor.
pub const LISTING_ROWS_MARKER: &str = "<!--spacefast:slot:listing-rows:v1-->";
pub const CHALLENGE_FILE: &str = "401.html";
pub const DENY_FILE: &str = "403.html";
pub const LAYOUT_FILE: &str = ".spacefast/templates/layout.html";

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerProtocolMetadata {
    pub config: ConfigProtocolMetadata,
    pub egress: EgressPolicyMetadata,
    pub space_config_schema: Value,
    pub strict_config_schema: Value,
    pub limits: FinalizerLimitMetadata,
    pub markers: FinalizerMarkerMetadata,
    pub paths: FinalizerPathMetadata,
    pub responses: ResponseProtocolMetadata,
}

/// The schema-v4 version artifact contract: what finalize writes and what the
/// serving engine reads.
#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResponseProtocolMetadata {
    pub schema: u64,
    pub schema_min: u64,
    pub schema_max: u64,
    pub schema_name: &'static str,
    pub hash_prefix_len: usize,
    pub root_pointer_file: &'static str,
    pub root_basename: &'static str,
    pub table_basename: &'static str,
    pub table_single_key: &'static str,
    pub table_split_bytes: usize,
    pub table_max_bytes: usize,
    pub entry_keys: ResponseEntryKeyMetadata,
    pub lane_accel: u64,
    pub lane_php: u64,
    pub cache_classes: ResponseCacheClassMetadata,
    pub special_keys: ResponseSpecialKeyMetadata,
    pub action_types: ResponseActionTypeMetadata,
    pub accel_surviving_headers: &'static [&'static str],
    pub platform_owned_header_prefixes: &'static [&'static str],
    pub platform_owned_headers: &'static [&'static str],
    pub provider_asset_extensions: &'static [&'static str],
    pub php_lane_body_max_bytes: u64,
    pub theme_stylesheet_path: &'static str,
    pub theme_stylesheet_url: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResponseEntryKeyMetadata {
    pub status: &'static str,
    pub headers: &'static str,
    pub blob: &'static str,
    pub etag: &'static str,
    pub length: &'static str,
    pub lane: &'static str,
    pub action: &'static str,
    pub cache_class: &'static str,
    pub rules_first: &'static str,
    pub allowlisted_ext: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResponseCacheClassMetadata {
    pub immutable: &'static str,
    pub html: &'static str,
    pub revalidate: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResponseSpecialKeyMetadata {
    pub spa: &'static str,
    pub not_found: &'static str,
    pub not_found_prefix: &'static str,
    pub rules: &'static str,
    pub robots: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ResponseActionTypeMetadata {
    pub zero: &'static str,
    pub function: &'static str,
    pub php: &'static str,
    pub proxy: &'static str,
    pub listing: &'static str,
    pub not_found: &'static str,
}

fn response_metadata() -> ResponseProtocolMetadata {
    ResponseProtocolMetadata {
        schema: ARTIFACT_SCHEMA_VERSION,
        schema_min: ARTIFACT_SCHEMA_MIN,
        schema_max: ARTIFACT_SCHEMA_MAX,
        schema_name: ARTIFACT_SCHEMA_NAME,
        hash_prefix_len: ARTIFACT_HASH_PREFIX_LEN,
        root_pointer_file: VERSION_ROOT_POINTER_FILE,
        root_basename: VERSION_ROOT_BASENAME,
        table_basename: RESPONSE_TABLE_BASENAME,
        table_single_key: RESPONSE_TABLE_SINGLE_KEY,
        table_split_bytes: RESPONSE_TABLE_SPLIT_BYTES,
        table_max_bytes: RESPONSE_TABLE_MAX_BYTES,
        entry_keys: ResponseEntryKeyMetadata {
            status: RESPONSE_ENTRY_STATUS,
            headers: RESPONSE_ENTRY_HEADERS,
            blob: RESPONSE_ENTRY_BLOB,
            etag: RESPONSE_ENTRY_ETAG,
            length: RESPONSE_ENTRY_LENGTH,
            lane: RESPONSE_ENTRY_LANE,
            action: RESPONSE_ENTRY_ACTION,
            cache_class: RESPONSE_ENTRY_CACHE_CLASS,
            rules_first: RESPONSE_ENTRY_RULES_FIRST,
            allowlisted_ext: RESPONSE_ENTRY_ALLOWLISTED_EXT,
        },
        lane_accel: RESPONSE_LANE_ACCEL,
        lane_php: RESPONSE_LANE_PHP,
        cache_classes: ResponseCacheClassMetadata {
            immutable: CACHE_CLASS_IMMUTABLE,
            html: CACHE_CLASS_HTML,
            revalidate: CACHE_CLASS_REVALIDATE,
        },
        special_keys: ResponseSpecialKeyMetadata {
            spa: RESPONSE_KEY_SPA,
            not_found: RESPONSE_KEY_NOT_FOUND,
            not_found_prefix: RESPONSE_KEY_NOT_FOUND_PREFIX,
            rules: RESPONSE_KEY_RULES,
            robots: RESPONSE_KEY_ROBOTS,
        },
        action_types: ResponseActionTypeMetadata {
            zero: RESPONSE_ACTION_ZERO,
            function: RESPONSE_ACTION_FUNCTION,
            php: RESPONSE_ACTION_PHP,
            proxy: RESPONSE_ACTION_PROXY,
            listing: RESPONSE_ACTION_LISTING,
            not_found: RESPONSE_ACTION_NOT_FOUND,
        },
        accel_surviving_headers: ACCEL_SURVIVING_HEADERS,
        platform_owned_header_prefixes: PLATFORM_OWNED_HEADER_PREFIXES,
        platform_owned_headers: PLATFORM_OWNED_HEADERS,
        provider_asset_extensions: PROVIDER_ASSET_EXTENSIONS,
        php_lane_body_max_bytes: PHP_LANE_BODY_MAX_BYTES,
        theme_stylesheet_path: THEME_STYLESHEET_PATH,
        theme_stylesheet_url: THEME_STYLESHEET_URL,
    }
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EgressPolicyMetadata {
    pub max_redirect_hops: u32,
    pub profiles: EgressProfilesMetadata,
    pub denied_ipv4: Vec<String>,
    pub denied_ipv6: Vec<String>,
    pub internal_hosts: &'static [&'static str],
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EgressProfilesMetadata {
    pub tenant_fetch: EgressProfileMetadata,
    pub proxy_route: EgressProfileMetadata,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct EgressProfileMetadata {
    pub allowed_schemes: &'static [&'static str],
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ConfigProtocolMetadata {
    pub current: ConfigFilePolicyMetadata,
    pub strict_v1: ConfigFilePolicyMetadata,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ConfigFilePolicyMetadata {
    pub canonical_file: &'static str,
    pub alias_files: &'static [&'static str],
    pub accepted_files: &'static [&'static str],
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerLimitMetadata {
    pub config_file_max_bytes: usize,
    pub config_overlay_max_bytes: usize,
    pub config_template_limit: usize,
    pub config_cron_limit: usize,
    pub config_cron_path_max_chars: usize,
    pub config_space_name_max_chars: usize,
    pub config_meta_title_max_chars: usize,
    pub config_meta_description_max_chars: usize,
    pub config_meta_image_max_chars: usize,
    pub config_inject_snippet_max_bytes: usize,
    pub config_inject_snippet_limit: usize,
    pub config_pages_color_max_chars: usize,
    pub config_pages_logo_max_chars: usize,
    pub config_pages_name_max_chars: usize,
    pub config_pages_font_family_max_chars: usize,
    pub config_build_timeout_min_seconds: u64,
    pub config_build_timeout_max_seconds: u64,
    pub config_fallback_status_min: u64,
    pub config_fallback_status_max: u64,
    pub config_superpowers_integration_id_max_chars: usize,
    pub config_superpowers_tag_id_max_chars: usize,
    pub config_superpowers_tag_name_max_chars: usize,
    pub config_superpowers_override_limit: usize,
    pub config_superpowers_override_reason_max_chars: usize,
    pub template_max_bytes: usize,
    pub template_variant_file_limit: usize,
    pub template_variant_route_limit: usize,
    pub template_variant_route_name_max_chars: usize,
    pub page_max_bytes: usize,
    pub gate_theme_file_max_bytes: usize,
    pub gate_theme_vars_max_bytes: usize,
    pub space_theme_css_max_bytes: usize,
    pub space_theme_preset_limit: usize,
    pub space_theme_slug_max_chars: usize,
    pub space_theme_value_max_chars: usize,
    pub space_theme_name_max_chars: usize,
    pub space_theme_size_max_chars: usize,
    pub space_theme_font_family_max_chars: usize,
    pub theme_json_font_family_limit: usize,
    pub zero_bundle_max_bytes: usize,
    pub zero_bundle_limit: usize,
    pub zero_static_file_limit: usize,
    pub zero_entry_limit: usize,
    pub zero_source_max_bytes: usize,
    pub zero_id_max_chars: usize,
    pub zero_route_path_max_chars: usize,
    pub zero_entry_name_max_chars: usize,
    pub zero_content_type_max_chars: usize,
    pub execution_timeout_ms_default: u64,
    pub execution_timeout_ms_max: u64,
    pub execution_memory_bytes_default: usize,
    pub execution_memory_bytes_max: usize,
    pub execution_body_bytes_default: usize,
    pub execution_body_bytes_max: usize,
    pub execution_output_bytes_default: usize,
    pub execution_output_bytes_max: usize,
    pub execution_subrequests_default: usize,
    pub execution_subrequests_max: usize,
    pub execution_db_operations_default: usize,
    pub execution_db_operations_max: usize,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ExecutionLimits {
    pub timeout_ms: u64,
    pub memory_bytes: usize,
    pub body_bytes: usize,
    pub output_bytes: usize,
    pub subrequests: usize,
    pub db_operations: usize,
}

impl Default for ExecutionLimits {
    fn default() -> Self {
        Self {
            timeout_ms: EXECUTION_TIMEOUT_MS_DEFAULT,
            memory_bytes: EXECUTION_MEMORY_BYTES_DEFAULT,
            body_bytes: EXECUTION_BODY_BYTES_DEFAULT,
            output_bytes: EXECUTION_OUTPUT_BYTES_DEFAULT,
            subrequests: EXECUTION_SUBREQUESTS_DEFAULT,
            db_operations: EXECUTION_DB_OPERATIONS_DEFAULT,
        }
    }
}

impl ExecutionLimits {
    pub fn validate(&self) -> Result<(), &'static str> {
        validate_execution_limit(self.timeout_ms, EXECUTION_TIMEOUT_MS_MAX, "timeout_ms")?;
        validate_execution_limit(
            self.memory_bytes,
            EXECUTION_MEMORY_BYTES_MAX,
            "memory_bytes",
        )?;
        validate_execution_limit(self.body_bytes, EXECUTION_BODY_BYTES_MAX, "body_bytes")?;
        validate_execution_limit(
            self.output_bytes,
            EXECUTION_OUTPUT_BYTES_MAX,
            "output_bytes",
        )?;
        validate_execution_limit(self.subrequests, EXECUTION_SUBREQUESTS_MAX, "subrequests")?;
        validate_execution_limit(
            self.db_operations,
            EXECUTION_DB_OPERATIONS_MAX,
            "db_operations",
        )
    }
}

fn validate_execution_limit<T>(value: T, max: T, name: &'static str) -> Result<(), &'static str>
where
    T: PartialEq + PartialOrd + From<u8>,
{
    if value == 0.into() || value > max {
        Err(name)
    } else {
        Ok(())
    }
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerMarkerMetadata {
    pub challenge: &'static str,
    pub deny: &'static str,
    pub layout: &'static str,
    pub badge: &'static str,
    pub listing_rows: &'static str,
}

#[derive(Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FinalizerPathMetadata {
    pub challenge: &'static str,
    pub deny: &'static str,
    pub layout: &'static str,
}

pub fn metadata() -> FinalizerProtocolMetadata {
    let policy = || ConfigFilePolicyMetadata {
        canonical_file: CONFIG_CANONICAL_FILE,
        alias_files: CONFIG_ALIAS_FILES,
        accepted_files: CONFIG_ACCEPTED_FILES,
    };
    FinalizerProtocolMetadata {
        config: ConfigProtocolMetadata {
            current: policy(),
            strict_v1: policy(),
        },
        egress: EgressPolicyMetadata {
            max_redirect_hops: EGRESS_MAX_REDIRECT_HOPS,
            profiles: EgressProfilesMetadata {
                tenant_fetch: EgressProfileMetadata {
                    allowed_schemes: EgressProfile::TenantFetch.allowed_schemes(),
                },
                proxy_route: EgressProfileMetadata {
                    allowed_schemes: EgressProfile::ProxyRoute.allowed_schemes(),
                },
            },
            denied_ipv4: DENIED_IPV4_NETWORKS
                .iter()
                .map(|(network, prefix)| format!("{network}/{prefix}"))
                .collect(),
            denied_ipv6: DENIED_IPV6_NETWORKS
                .iter()
                .map(|(network, prefix)| format!("{network}/{prefix}"))
                .collect(),
            internal_hosts: SERVING_INTERNAL_HOSTS,
        },
        space_config_schema: crate::config::current::public_json_schema(),
        strict_config_schema: crate::config::strict::public_json_schema(),
        limits: FinalizerLimitMetadata {
            config_file_max_bytes: CONFIG_FILE_MAX_BYTES,
            config_overlay_max_bytes: CONFIG_OVERLAY_MAX_BYTES,
            config_template_limit: CONFIG_TEMPLATE_LIMIT,
            config_cron_limit: CONFIG_CRON_LIMIT,
            config_cron_path_max_chars: CONFIG_CRON_PATH_MAX_CHARS,
            config_space_name_max_chars: CONFIG_SPACE_NAME_MAX_CHARS,
            config_meta_title_max_chars: CONFIG_META_TITLE_MAX_CHARS,
            config_meta_description_max_chars: CONFIG_META_DESCRIPTION_MAX_CHARS,
            config_meta_image_max_chars: CONFIG_META_IMAGE_MAX_CHARS,
            config_inject_snippet_max_bytes: CONFIG_INJECT_SNIPPET_MAX_BYTES,
            config_inject_snippet_limit: CONFIG_INJECT_SNIPPET_LIMIT,
            config_pages_color_max_chars: CONFIG_PAGES_COLOR_MAX_CHARS,
            config_pages_logo_max_chars: CONFIG_PAGES_LOGO_MAX_CHARS,
            config_pages_name_max_chars: CONFIG_PAGES_NAME_MAX_CHARS,
            config_pages_font_family_max_chars: CONFIG_PAGES_FONT_FAMILY_MAX_CHARS,
            config_build_timeout_min_seconds: CONFIG_BUILD_TIMEOUT_MIN_SECONDS,
            config_build_timeout_max_seconds: CONFIG_BUILD_TIMEOUT_MAX_SECONDS,
            config_fallback_status_min: CONFIG_FALLBACK_STATUS_MIN,
            config_fallback_status_max: CONFIG_FALLBACK_STATUS_MAX,
            config_superpowers_integration_id_max_chars:
                CONFIG_SUPERPOWERS_INTEGRATION_ID_MAX_CHARS,
            config_superpowers_tag_id_max_chars: CONFIG_SUPERPOWERS_TAG_ID_MAX_CHARS,
            config_superpowers_tag_name_max_chars: CONFIG_SUPERPOWERS_TAG_NAME_MAX_CHARS,
            config_superpowers_override_limit: CONFIG_SUPERPOWERS_OVERRIDE_LIMIT,
            config_superpowers_override_reason_max_chars:
                CONFIG_SUPERPOWERS_OVERRIDE_REASON_MAX_CHARS,
            template_max_bytes: TEMPLATE_MAX_BYTES,
            template_variant_file_limit: TEMPLATE_VARIANT_FILE_LIMIT,
            template_variant_route_limit: TEMPLATE_VARIANT_ROUTE_LIMIT,
            template_variant_route_name_max_chars: TEMPLATE_VARIANT_ROUTE_NAME_MAX_CHARS,
            page_max_bytes: PAGE_MAX_BYTES,
            gate_theme_file_max_bytes: GATE_THEME_FILE_MAX_BYTES,
            gate_theme_vars_max_bytes: GATE_THEME_VARS_MAX_BYTES,
            space_theme_css_max_bytes: SPACE_THEME_CSS_MAX_BYTES,
            space_theme_preset_limit: SPACE_THEME_PRESET_LIMIT,
            space_theme_slug_max_chars: SPACE_THEME_SLUG_MAX_CHARS,
            space_theme_value_max_chars: SPACE_THEME_VALUE_MAX_CHARS,
            space_theme_name_max_chars: SPACE_THEME_NAME_MAX_CHARS,
            space_theme_size_max_chars: SPACE_THEME_SIZE_MAX_CHARS,
            space_theme_font_family_max_chars: SPACE_THEME_FONT_FAMILY_MAX_CHARS,
            theme_json_font_family_limit: THEME_JSON_FONT_FAMILY_LIMIT,
            zero_bundle_max_bytes: ZERO_BUNDLE_MAX_BYTES,
            zero_bundle_limit: ZERO_BUNDLE_LIMIT,
            zero_static_file_limit: ZERO_STATIC_FILE_LIMIT,
            zero_entry_limit: ZERO_ENTRY_LIMIT,
            zero_source_max_bytes: ZERO_SOURCE_MAX_BYTES,
            zero_id_max_chars: ZERO_ID_MAX_CHARS,
            zero_route_path_max_chars: ZERO_ROUTE_PATH_MAX_CHARS,
            zero_entry_name_max_chars: ZERO_ENTRY_NAME_MAX_CHARS,
            zero_content_type_max_chars: ZERO_CONTENT_TYPE_MAX_CHARS,
            execution_timeout_ms_default: EXECUTION_TIMEOUT_MS_DEFAULT,
            execution_timeout_ms_max: EXECUTION_TIMEOUT_MS_MAX,
            execution_memory_bytes_default: EXECUTION_MEMORY_BYTES_DEFAULT,
            execution_memory_bytes_max: EXECUTION_MEMORY_BYTES_MAX,
            execution_body_bytes_default: EXECUTION_BODY_BYTES_DEFAULT,
            execution_body_bytes_max: EXECUTION_BODY_BYTES_MAX,
            execution_output_bytes_default: EXECUTION_OUTPUT_BYTES_DEFAULT,
            execution_output_bytes_max: EXECUTION_OUTPUT_BYTES_MAX,
            execution_subrequests_default: EXECUTION_SUBREQUESTS_DEFAULT,
            execution_subrequests_max: EXECUTION_SUBREQUESTS_MAX,
            execution_db_operations_default: EXECUTION_DB_OPERATIONS_DEFAULT,
            execution_db_operations_max: EXECUTION_DB_OPERATIONS_MAX,
        },
        markers: FinalizerMarkerMetadata {
            challenge: CHALLENGE_MARKER,
            deny: DENY_MARKER,
            layout: LAYOUT_MARKER,
            badge: BADGE_MARKER,
            listing_rows: LISTING_ROWS_MARKER,
        },
        paths: FinalizerPathMetadata {
            challenge: CHALLENGE_FILE,
            deny: DENY_FILE,
            layout: LAYOUT_FILE,
        },
        responses: response_metadata(),
    }
}

#[must_use]
pub fn typescript_source() -> String {
    let json = serde_json::to_string_pretty(&metadata()).expect("protocol metadata serializes");
    format!(
        "// @generated by `cargo run -p stattic-runtime-compiler --bin protocol-codegen`.\n// Rust `protocol.rs` and the Rust config compilers are the only editable authorities.\n\nexport const FINALIZER_PROTOCOL = {json} as const;\n\nexport type FinalizerProtocolMetadata = typeof FINALIZER_PROTOCOL;\n{}",
        crate::config::current::TYPESCRIPT_CONFIG_TYPES
    )
}

/// PHP protocol constants for the serving engine. Native-only because the
/// Zero runner ABI identifiers come from the native `stattic-zero-runner`
/// crate.
#[cfg(not(target_family = "wasm"))]
#[must_use]
pub fn php_source() -> String {
    let denied_ipv4 = php_string_array(
        &DENIED_IPV4_NETWORKS
            .iter()
            .map(|(network, prefix)| format!("{network}/{prefix}"))
            .collect::<Vec<_>>(),
    );
    let denied_ipv6 = php_string_array(
        &DENIED_IPV6_NETWORKS
            .iter()
            .map(|(network, prefix)| format!("{network}/{prefix}"))
            .collect::<Vec<_>>(),
    );
    let internal_hosts = php_string_array(SERVING_INTERNAL_HOSTS);
    let tenant_fetch_schemes = php_string_array(EgressProfile::TenantFetch.allowed_schemes());
    let proxy_route_schemes = php_string_array(EgressProfile::ProxyRoute.allowed_schemes());
    let provider_asset_extensions = php_string_array(PROVIDER_ASSET_EXTENSIONS);
    let platform_owned_prefixes = php_string_array(PLATFORM_OWNED_HEADER_PREFIXES);
    let platform_owned_headers = php_string_array(PLATFORM_OWNED_HEADERS);
    let responses = format!(
        "\nconst STATTIC_RUNTIME_ARTIFACT_SCHEMA = {ARTIFACT_SCHEMA_VERSION};\nconst STATTIC_RUNTIME_ARTIFACT_SCHEMA_MIN = {ARTIFACT_SCHEMA_MIN};\nconst STATTIC_RUNTIME_ARTIFACT_SCHEMA_MAX = {ARTIFACT_SCHEMA_MAX};\nconst STATTIC_RUNTIME_ARTIFACT_SCHEMA_NAME = '{ARTIFACT_SCHEMA_NAME}';\nconst STATTIC_RUNTIME_ARTIFACT_HASH_PREFIX_LEN = {ARTIFACT_HASH_PREFIX_LEN};\nconst STATTIC_RUNTIME_VERSION_ROOT_POINTER_FILE = '{VERSION_ROOT_POINTER_FILE}';\nconst STATTIC_RUNTIME_VERSION_ROOT_BASENAME = '{VERSION_ROOT_BASENAME}';\nconst STATTIC_RUNTIME_RESPONSE_TABLE_BASENAME = '{RESPONSE_TABLE_BASENAME}';\nconst STATTIC_RUNTIME_RESPONSE_TABLE_SINGLE_KEY = '{RESPONSE_TABLE_SINGLE_KEY}';\nconst STATTIC_RUNTIME_RESPONSE_TABLE_SPLIT_BYTES = {RESPONSE_TABLE_SPLIT_BYTES};\nconst STATTIC_RUNTIME_RESPONSE_TABLE_MAX_BYTES = {RESPONSE_TABLE_MAX_BYTES};\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_STATUS = '{RESPONSE_ENTRY_STATUS}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_HEADERS = '{RESPONSE_ENTRY_HEADERS}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_BLOB = '{RESPONSE_ENTRY_BLOB}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_ETAG = '{RESPONSE_ENTRY_ETAG}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_LENGTH = '{RESPONSE_ENTRY_LENGTH}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_LANE = '{RESPONSE_ENTRY_LANE}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_ACTION = '{RESPONSE_ENTRY_ACTION}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_CACHE_CLASS = '{RESPONSE_ENTRY_CACHE_CLASS}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_RULES_FIRST = '{RESPONSE_ENTRY_RULES_FIRST}';\nconst STATTIC_RUNTIME_RESPONSE_ENTRY_ALLOWLISTED_EXT = '{RESPONSE_ENTRY_ALLOWLISTED_EXT}';\nconst STATTIC_RUNTIME_RESPONSE_LANE_ACCEL = {RESPONSE_LANE_ACCEL};\nconst STATTIC_RUNTIME_RESPONSE_LANE_PHP = {RESPONSE_LANE_PHP};\nconst STATTIC_RUNTIME_CACHE_CLASS_IMMUTABLE = '{CACHE_CLASS_IMMUTABLE}';\nconst STATTIC_RUNTIME_CACHE_CLASS_HTML = '{CACHE_CLASS_HTML}';\nconst STATTIC_RUNTIME_CACHE_CLASS_REVALIDATE = '{CACHE_CLASS_REVALIDATE}';\nconst STATTIC_RUNTIME_RESPONSE_KEY_SPA = \"\\0spa\";\nconst STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND = \"\\x00404\";\nconst STATTIC_RUNTIME_RESPONSE_KEY_NOT_FOUND_PREFIX = \"\\x00404:\";\nconst STATTIC_RUNTIME_RESPONSE_KEY_RULES = \"\\0rules\";\nconst STATTIC_RUNTIME_RESPONSE_KEY_ROBOTS = \"\\0robots\";\nconst STATTIC_RUNTIME_RESPONSE_ACTION_ZERO = '{RESPONSE_ACTION_ZERO}';\nconst STATTIC_RUNTIME_RESPONSE_ACTION_FUNCTION = '{RESPONSE_ACTION_FUNCTION}';\nconst STATTIC_RUNTIME_RESPONSE_ACTION_PHP = '{RESPONSE_ACTION_PHP}';\nconst STATTIC_RUNTIME_RESPONSE_ACTION_PROXY = '{RESPONSE_ACTION_PROXY}';\nconst STATTIC_RUNTIME_RESPONSE_ACTION_LISTING = '{RESPONSE_ACTION_LISTING}';\nconst STATTIC_RUNTIME_RESPONSE_ACTION_NOT_FOUND = '{RESPONSE_ACTION_NOT_FOUND}';\nconst STATTIC_RUNTIME_PLATFORM_OWNED_HEADER_PREFIXES = {platform_owned_prefixes};\nconst STATTIC_RUNTIME_PLATFORM_OWNED_HEADERS = {platform_owned_headers};\nconst STATTIC_RUNTIME_PROVIDER_ASSET_EXTENSIONS = {provider_asset_extensions};\nconst STATTIC_RUNTIME_PHP_LANE_BODY_MAX_BYTES = {PHP_LANE_BODY_MAX_BYTES};\nconst STATTIC_RUNTIME_THEME_STYLESHEET_PATH = '{THEME_STYLESHEET_PATH}';\nconst STATTIC_RUNTIME_THEME_STYLESHEET_URL = '{THEME_STYLESHEET_URL}';\nconst STATTIC_RUNTIME_LISTING_ROWS_MARKER = '{LISTING_ROWS_MARKER}';\n"
    );
    format!(
        "<?php\ndeclare(strict_types=1);\n\n// @generated by `cargo run -p stattic-runtime-compiler --bin protocol-codegen -- --php`.\n// Rust protocol and Zero runner constants are the only editable authorities.\n\nconst STATTIC_RUNTIME_ZERO_RUNNER_ABI = '{}';\nconst STATTIC_RUNTIME_ZERO_QUICKJS_ABI = '{}';\nconst STATTIC_RUNTIME_ZERO_BUNDLE_MAX_BYTES = {};\nconst STATTIC_RUNTIME_ZERO_BUNDLE_LIMIT = {};\nconst STATTIC_RUNTIME_EGRESS_MAX_REDIRECT_HOPS = {};\nconst STATTIC_RUNTIME_EGRESS_TENANT_FETCH_ALLOWED_SCHEMES = {};\nconst STATTIC_RUNTIME_EGRESS_PROXY_ROUTE_ALLOWED_SCHEMES = {};\nconst STATTIC_RUNTIME_EGRESS_DENIED_IPV4 = {};\nconst STATTIC_RUNTIME_EGRESS_DENIED_IPV6 = {};\nconst STATTIC_RUNTIME_EGRESS_INTERNAL_HOSTS = {};\nconst STATTIC_RUNTIME_BROKER_PROTOCOL = '{}';\nconst STATTIC_RUNTIME_EXECUTION_TIMEOUT_MS_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_TIMEOUT_MS_MAX = {};\nconst STATTIC_RUNTIME_EXECUTION_MEMORY_BYTES_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_MEMORY_BYTES_MAX = {};\nconst STATTIC_RUNTIME_EXECUTION_BODY_BYTES_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_BODY_BYTES_MAX = {};\nconst STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_OUTPUT_BYTES_MAX = {};\nconst STATTIC_RUNTIME_EXECUTION_SUBREQUESTS_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_SUBREQUESTS_MAX = {};\nconst STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_DEFAULT = {};\nconst STATTIC_RUNTIME_EXECUTION_DB_OPERATIONS_MAX = {};\n{}",
        stattic_zero_runner::RUNNER_ABI,
        stattic_zero_runner::QUICKJS_ABI,
        ZERO_BUNDLE_MAX_BYTES,
        ZERO_BUNDLE_LIMIT,
        EGRESS_MAX_REDIRECT_HOPS,
        tenant_fetch_schemes,
        proxy_route_schemes,
        denied_ipv4,
        denied_ipv6,
        internal_hosts,
        BROKER_PROTOCOL,
        EXECUTION_TIMEOUT_MS_DEFAULT,
        EXECUTION_TIMEOUT_MS_MAX,
        EXECUTION_MEMORY_BYTES_DEFAULT,
        EXECUTION_MEMORY_BYTES_MAX,
        EXECUTION_BODY_BYTES_DEFAULT,
        EXECUTION_BODY_BYTES_MAX,
        EXECUTION_OUTPUT_BYTES_DEFAULT,
        EXECUTION_OUTPUT_BYTES_MAX,
        EXECUTION_SUBREQUESTS_DEFAULT,
        EXECUTION_SUBREQUESTS_MAX,
        EXECUTION_DB_OPERATIONS_DEFAULT,
        EXECUTION_DB_OPERATIONS_MAX,
        responses,
    )
}

#[cfg(not(target_family = "wasm"))]
fn php_string_array(values: &[impl AsRef<str>]) -> String {
    format!(
        "[{}]",
        values
            .iter()
            .map(|value| format!("'{}'", value.as_ref()))
            .collect::<Vec<_>>()
            .join(", ")
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    // The checked-in TypeScript protocol is regenerated and formatter-cleaned
    // by `scripts/check-finalizer-protocol.mjs`; it cannot be byte-compared
    // here because the repo formatter rewrites the emitted JSON literal. The
    // PHP output is byte-exact against the Rust emitter.
    #[test]
    fn checked_in_php_protocol_is_current() {
        assert_eq!(
            super::php_source(),
            include_str!(concat!(
                env!("CARGO_MANIFEST_DIR"),
                "/../../runtime/engine/shared/finalizer-protocol.generated.php"
            ))
        );
    }

    #[test]
    fn execution_limits_validate_declared_bounds() {
        let defaults = ExecutionLimits::default();
        assert_eq!(defaults.validate(), Ok(()));
        macro_rules! bounds {
            ($field:ident, $max:ident) => {{
                let mut limits = defaults;
                limits.$field = 0;
                assert_eq!(limits.validate(), Err(stringify!($field)));
                limits.$field = $max + 1;
                assert_eq!(limits.validate(), Err(stringify!($field)));
                limits.$field = $max;
                assert_eq!(limits.validate(), Ok(()));
            }};
        }
        bounds!(timeout_ms, EXECUTION_TIMEOUT_MS_MAX);
        bounds!(memory_bytes, EXECUTION_MEMORY_BYTES_MAX);
        bounds!(body_bytes, EXECUTION_BODY_BYTES_MAX);
        bounds!(output_bytes, EXECUTION_OUTPUT_BYTES_MAX);
        bounds!(subrequests, EXECUTION_SUBREQUESTS_MAX);
        bounds!(db_operations, EXECUTION_DB_OPERATIONS_MAX);
    }
}
