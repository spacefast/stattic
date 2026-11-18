//! What a 0.8 publish served that the candidate does not reproduce. A candidate replaces the
//! published version, so anything it leaves out disappears from the Space: every such file and
//! setting is an error (`Z08_OMITTED_*`), never a silent drop.
//!
//! A 0.8 Zero publish served the project root as static content, minus the capsule's own source
//! (`client/`, `server/`, `shared/`, `pages/` and source code anywhere), repository files, its
//! config and the platform's ignore list (zero-compile's `isZeroPublicStaticPath`). Those static
//! files that v2 can serve unchanged are carried into the candidate's `public/`, which v2 serves at
//! the same URLs. The rest are refused here with what replaces them.
use super::super::{Failure, fail, pointer};
use crate::loader::Fs;
use regex::{Regex, RegexBuilder};
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};
use std::sync::LazyLock;

pub(super) const CLIENT: &str = "the 0.8 client app is not carried over";
pub(super) const PAGES: &str = "the 0.8 page is not carried over";
pub(super) const STATIC: &str = "the static file is not carried over";
pub(super) const REDIRECTS: &str = "the redirect and header rule files are not carried over";
pub(super) const SERVE: &str = "the serving settings are not carried over";
/// A capsule role the Space config can't declare as it is (`app::connector_config`).
pub(super) const CONNECTORS: &str = "the connector role is not carried over";
pub(super) const SYNC: &str = "content sync is not carried over";
pub(super) const POSTS: &str = "the posts content is not carried over";
pub(super) const PUBLIC: &str = "public/ is not carried over";
pub(super) const SYMLINK: &str = "the symbolic link is not carried over";
pub(super) const DENIED: &str = "the credential file is not carried over";
pub(super) const CONFIG: &str = "the setting is not carried over";
pub(super) const CRONS: &str = "the cron is not carried over";
pub(super) const UNVERIFIED: &str = "the unverified static file is not carried over";
pub(super) const UNVERIFIED_CONFIG: &str = "the unverified config is not carried over";

/// The monorepo's one source deny list, verbatim: `DENIED_SOURCE_PATH_PATTERN` in
/// packages/common/src/utils/source-archive-policy.ts, compiled case-insensitively as there.
/// A directory with one of these names is denied with its whole subtree.
const DENIED_SOURCE_PATH_PATTERN: &str = r"(?:^|/)(?:\.git|\.hg|\.svn|node_modules|\.spacefast|\.stattic|\.ssh|\.aws|\.npmrc|\.netrc|\.pypirc|credentials\.json|\.env(?:rc)?(?:\.[^/]*)?|\.dev\.vars(?:\.[^/]*)?|(?:[^/]*[._-])?id_(?:rsa|dsa|ecdsa|ed25519)(?:[._-][^/]*)?|(?:[^/]*[.-])?private[.-]?key|[^/]*\.(?:pem|key|p12|pfx|crt|jks|keystore))(?:/|$)";
/// `OS_METADATA_PATH_PATTERN` in the same file, case-sensitive.
const OS_METADATA_PATH_PATTERN: &str = r"(?:^|/)(?:\.DS_Store|__MACOSX|\._[^/]*)(?:/|$)";
static DENIED_SOURCE_PATH: LazyLock<Regex> = LazyLock::new(|| {
    RegexBuilder::new(DENIED_SOURCE_PATH_PATTERN)
        .case_insensitive(true)
        .build()
        .expect("deny list")
});
static OS_METADATA_PATH: LazyLock<Regex> = LazyLock::new(|| Regex::new(OS_METADATA_PATH_PATTERN).expect("OS metadata"));
/// Denied directories that hold checkouts and dependencies, not credentials: never source or
/// content, and nearly every local checkout has one, so they are skipped without an error.
const SKIPPED_DIRECTORIES: &[&str] = &[".git", ".hg", ".svn", "node_modules", ".spacefast", ".stattic"];
/// Directories 0.8 never published beyond the deny list (publish-policy's `IGNORED_SEGMENTS`).
const UNPUBLISHED_DIRECTORIES: &[&str] = &[".wrangler", ".vercel", ".netlify", ".turbo", ".cache", ".tp"];
/// publish-policy's `publishPathIgnored` beyond its segment lists: Next's build cache and the
/// CLI's own temporary and lock files, at any depth (matched on the lowercased path).
const IGNORED_PATH_PATTERN: &str = r"(?:^|/)\.next/cache(?:/|$)|(?:^|/)\.spacefast-(?:[0-9a-f-]{36}\.(?:tmp(?:\.mjs)?|rollback|retired)|[0-9a-f]{24}\.lock)$";
static IGNORED_PATH: LazyLock<Regex> = LazyLock::new(|| Regex::new(IGNORED_PATH_PATTERN).expect("ignored paths"));
/// publish-policy's `IGNORED_EXACT_PATHS` that the deny list doesn't already cover: 0.8 dropped
/// a path when any lowercased segment equals one, at any depth.
const IGNORED_EXACT_SEGMENTS: &[&str] = &[".gitignore", "_routes.json"];
/// zero-compile's `CAPSULE_TSCONFIG_FILE`.
static TSCONFIG: LazyLock<Regex> = LazyLock::new(|| Regex::new(r"^tsconfig(?:\.[^/]+)?\.json$").expect("tsconfig"));
// The paths 0.8's publish kept out as the runtime's own (`runtimeOwnedPaths`, the
// `static_runtime_control_path_not_supported` paths of publish-policy's `staticUploadPathViolation`)
// and the execution-control files that policy refuses. Lowercase, compared with the folded path.
const STATIC_EXECUTION_CONTROL_FILES: &[&str] = &[".htaccess", ".user.ini", "spacefast.config.ts"];
const STATIC_RUNTIME_CONTROL_SEGMENT_PREFIXES: &[&str] = &["__spacefast", "__stattic"];
const STATIC_RUNTIME_ROOT_SEGMENTS: &[&str] = &["__sf", "__span", "__zero"];
const STATIC_RUNTIME_CONTROL_PATHS: &[&str] = &[".well-known/spacefast-runtime", ".well-known/stattic-runtime"];
const STATIC_RUNTIME_CONTROL_PREFIXES: &[&str] = &[
    ".zero-compiled",
    ".spacefast/storage",
    ".spacefast/engine",
    ".stattic/storage",
    ".stattic/engine",
];
/// The subtrees under the runtime prefix a publish may still write (`isPublishableBuildArtifactPath`).
const PUBLISHABLE_BUILD_ARTIFACT_PREFIXES: &[&str] =
    &["__spacefast/functions/bundles/", "__spacefast/functions/seeds/"];

/// Whether 0.8's publish kept `relative` out as a runtime control path or execution-control file.
fn runtime_owned(relative: &str) -> bool {
    let lower = relative.to_ascii_lowercase();
    let segments: Vec<&str> = relative.split('/').collect();
    let name = lower.rsplit('/').next().unwrap_or(&lower);
    STATIC_EXECUTION_CONTROL_FILES.contains(&name)
        || STATIC_RUNTIME_ROOT_SEGMENTS.contains(&segments[0].to_ascii_lowercase().as_str())
        || segments[0] == "identity"
        || (segments.iter().any(|s| {
            let s = s.to_ascii_lowercase();
            STATIC_RUNTIME_CONTROL_SEGMENT_PREFIXES.iter().any(|p| s.starts_with(p))
        }) && !PUBLISHABLE_BUILD_ARTIFACT_PREFIXES.iter().any(|p| lower.starts_with(p)))
        || STATIC_RUNTIME_CONTROL_PREFIXES
            .iter()
            .any(|p| lower == *p || lower.starts_with(&format!("{p}/")))
        || STATIC_RUNTIME_CONTROL_PATHS.contains(&lower.as_str())
        || IGNORED_PATH.is_match(&lower)
}

/// Roots that hold the capsule's source, never served as content.
const SOURCE_ROOTS: &[&str] = &["client", "server", "shared", "pages"];
/// Repository files beside a capsule.
const REPOSITORY_FILES: &[&str] = &[
    "AGENTS.md",
    "LICENSE",
    "README.md",
    "bun.lock",
    "bun.lockb",
    "npm-shrinkwrap.json",
    "package-lock.json",
    "pnpm-lock.yaml",
    "yarn.lock",
    "package.json",
];
const REPOSITORY_ROOTS: &[&str] = &[".github", "test", "tests", "tools"];
/// zero-compile's `ZERO_SOURCE_CODE_EXTENSIONS`.
const CODE: &[&str] = &["js", "jsx", "mjs", "cjs", "ts", "tsx", "mts", "cts"];
/// zero-compile's `TYPESCRIPT_SOURCE_FILE`, never served wherever it is.
const TYPESCRIPT: &[&str] = &["ts", "tsx", "mts", "cts"];
/// The rule files 0.8 compiled into routing instead of serving. The candidate doesn't carry them:
/// in public/ they would publish at the root, which this converter hasn't shown to apply the same.
const RULE_FILES: &[&str] = &["_redirects", "_headers"];
/// Config keys the candidate reproduces or that change nothing it serves: the converted runtime,
/// the Space's display name (and the old CLI's `space` key) and the editor's schema pointer.
const NO_OP_KEYS: &[&str] = &["$schema", "runtime", "name", "space"];
/// Keys Spacefast applies to the Space, not the Zero app, and that an independent review traced to
/// the same effect on a v2 version as on the 0.8 one; written unchanged into the candidate's own
/// sf.jsonc, which the migrated publish uploads.
/// - `redirects`: the rules stage runs before entries, pages and WordPress, and a redirect doesn't
///   yield to an existing path (monorepo runtime/engine/runtime/serve.php, redirects.php). Only
///   when no rule can reach a URL the v2 app answers that 0.8 kept out of the redirect stage:
///   WordPress's and TypedPress's paths (`/__tp/api/v1/*` and `/wp-json/*`; reserved first segments,
///   as 0.8's data URLs were) and the folders the candidate serves (see `redirect_reaches_app`).
/// - `cleanUrls`, `index`: compiled over the static files, which v2 publishes from public/.
/// - `analytics`, `system`: frozen from the file config whatever the runtime.
/// - `build`, `placement`: read from the repository or the Space at build or creation time, never
///   from the version, so carrying them changes nothing; kept so the file still says what it said.
/// - `access` as `"public"`, the only string form Spacefast accepts: no path patterns, and v2's
///   `/wp-json` lane enforces it through the same access engine (see `carries`).
///
/// Every other key is refused. Path rules don't survive the move, because the app's data URLs
/// move from 0.8's `/__zero/*` to TypedPress's `/__tp/api/v1/*`: so `access` path patterns, `cache`
/// and `firewall` are refused, as is `sell` (its product file and paid assets would be swept into
/// public/).
///
/// `crons` is carried when every entry still names something the candidate answers at the same
/// path with GET (the Spacefast host calls a cron's path with GET): a converted GET endpoint, or a
/// carried static file. The host keeps scheduling it and adds its `x-spacefast-cron` and
/// `Authorization: Bearer <CRON_SECRET>` headers, which a v2 route reads from `req.headers`.
///
/// `connectors`: finalize freezes the config's roles for every version whatever its runtime, and
/// the engine answers `/__spacefast/connectors/*` before WordPress runs, so pages keep calling
/// them. When the file declares roles, Spacefast served those instead of the capsule's.
pub(super) const PLATFORM_KEYS: &[&str] = &[
    "redirects",
    "cleanUrls",
    "index",
    "analytics",
    "build",
    "placement",
    "system",
    "access",
    "crons",
    "connectors",
];

/// Where a cron the candidate carries can point: the GET routes it serves at their 0.8 paths, and
/// the static files it carries (`/<name>`).
pub(super) struct Targets<'a> {
    pub get_paths: &'a BTreeSet<String>,
    pub files: BTreeSet<String>,
}

impl Targets<'_> {
    /// The first cron entry whose path the candidate doesn't answer with GET, by index.
    pub(super) fn unanswered(&self, crons: &serde_json::Value) -> Option<(usize, String)> {
        let Some(entries) = crons.as_array() else {
            return Some((0, String::new()));
        };
        entries.iter().enumerate().find_map(|(i, cron)| {
            let path = cron.get("path").and_then(serde_json::Value::as_str).unwrap_or("");
            (!(self.get_paths.contains(path) || self.files.contains(path))).then(|| (i, path.to_string()))
        })
    }
}

/// Whether the candidate carries `key` with `value`: every platform key, `access` only as
/// `"public"`, and `redirects` only when no rule can reach a URL the v2 app answers that 0.8 kept
/// out of the redirect stage. `served` holds the first segments of every file the candidate
/// writes under public/ (see `redirect_reaches_app`).
pub(super) fn carries(key: &str, value: &serde_json::Value, served: &BTreeSet<String>, targets: &Targets) -> bool {
    PLATFORM_KEYS.contains(&key)
        && match key {
            "access" => value.as_str() == Some("public"),
            "crons" => targets.unanswered(value).is_none(),
            "redirects" => value
                .as_array()
                .is_some_and(|rules| rules.iter().all(|r| !redirect_reaches_app(r, served))),
            _ => true,
        }
}

/// Whether a redirect rule's `source` can reach a URL the v2 app answers that 0.8 kept out of the
/// redirect stage, so a carried rule would catch requests it never caught on 0.8. That is any of:
/// - an absolute URL or an empty source;
/// - a first segment holding a pattern character (the engine compiles `:name` anywhere in a
///   segment to `[^/]+`, so `/w:x` matches `/wp-json`, and `*` to a splat);
/// - a first segment WordPress or TypedPress owns (`app::RESERVED_TP`, `wp-json` among them), or one
///   starting with `wp-` (WordPress's own entry points);
/// - a first segment the candidate serves files under (`served`, from its public/ tree: the
///   converted client's `app/`, `tp/` and stylesheet folders, and the carried static files).
///
/// The last one is read from what the candidate writes, so it covers every folder the converter
/// adds. A carried static file keeps its 0.8 path and was a table entry on 0.8 too, so a rule
/// there behaves the same; refusing it only refuses more than needed. Exactly `/` matches only the
/// root. Anything this can't read counts as reaching one.
fn redirect_reaches_app(rule: &serde_json::Value, served: &BTreeSet<String>) -> bool {
    let Some(source) = rule.get("source").and_then(serde_json::Value::as_str) else {
        return true;
    };
    if source == "/" {
        return false;
    }
    let Some(path) = source.strip_prefix('/') else {
        return true;
    };
    let first = path.split('/').next().unwrap_or("");
    let lower = first.to_ascii_lowercase();
    first.is_empty()
        || first.contains([':', '*'])
        || lower.starts_with("wp-")
        || crate::app::RESERVED_TP.contains(&lower.as_str())
        || served.contains(&lower)
}
/// The WordPress-style theme file 0.8 read for the pages' theme tokens (`THEME_JSON_FILE`).
const THEME_JSON: &str = "theme.json";
/// The serving keys with their own error; any other key raises `CONFIG`.
/// - `rewrites`, `listing`: the engine checks a rewrite's path, and compiles a listing, against
///   0.8's pages; v2's render in WordPress, so the rule would take over the v2 app's URLs.
/// - `headers`: header rules apply to response-table entries only, so on v2 they'd skip the app's
///   WordPress-rendered pages that they covered on 0.8.
/// - `fallback`: not shown to leave v2's WordPress-rendered pages alone.
/// - `inject`: not shown to reach WordPress-rendered HTML.
/// - `serve`: 0.8's own serving block.
const SERVING_KEYS: &[(&str, &str)] = &[
    ("rewrites", SERVE),
    ("headers", SERVE),
    ("listing", SERVE),
    ("fallback", SERVE),
    ("serve", SERVE),
    ("inject", SERVE),
];

/// Files this sweep carries over (`public/<path>` → contents) and the errors for the rest.
pub(super) struct Omitted {
    pub files: BTreeMap<String, String>,
    pub carried: Vec<String>,
    /// The candidate's sf.jsonc: the 0.8 config's platform keys, when it has any.
    pub config: Option<String>,
    /// The 0.8 config keys the candidate carries, in their order (`carries`).
    pub kept: serde_json::Map<String, serde_json::Value>,
}

/// What the sweep does with a capsule-relative path before deciding whether 0.8 served it.
#[derive(PartialEq)]
enum Policy {
    /// Never source or content (OS metadata, checkouts, dependencies, unpublished tool state).
    Skip,
    /// On the source deny list as a credential: an error, never carried and never dropped.
    Denied,
    Keep,
}

fn policy(relative: &str) -> Policy {
    if OS_METADATA_PATH.is_match(relative) {
        return Policy::Skip;
    }
    // The first denied segment decides: `.git/.env` is checkout state, `.env/x` a credential.
    for segment in relative.split('/') {
        if DENIED_SOURCE_PATH.is_match(segment) {
            let lower = segment.to_ascii_lowercase();
            return if SKIPPED_DIRECTORIES.contains(&lower.as_str()) {
                Policy::Skip
            } else {
                Policy::Denied
            };
        }
        if UNPUBLISHED_DIRECTORIES.contains(&segment)
            || IGNORED_EXACT_SEGMENTS.contains(&segment.to_ascii_lowercase().as_str())
        {
            return Policy::Skip;
        }
    }
    Policy::Keep
}

/// Whether 0.8 served `relative` as static content (zero-compile's `isZeroPublicStaticPath`).
fn served(relative: &str) -> bool {
    let root = relative.split('/').next().unwrap_or("");
    let extension = Path::new(relative).extension().and_then(|e| e.to_str()).unwrap_or("");
    !(SOURCE_ROOTS.contains(&root)
        || REPOSITORY_ROOTS.contains(&root)
        || REPOSITORY_FILES.contains(&relative)
        || super::CONFIG_FILES.contains(&relative)
        || relative == "spacefast.config.ts"
        || relative == THEME_JSON
        // isZeroCapsuleOwnedPath: code inside a source root, and package.json at any depth.
        || (root == "content" && CODE.contains(&extension))
        || relative.rsplit('/').next() == Some("package.json")
        // isZeroProjectOnlyPath: TypeScript anywhere and tsconfig files. Plain JavaScript outside
        // the source roots is the author's content, and 0.8 served it.
        || TSCONFIG.is_match(relative)
        || TYPESCRIPT.iter().any(|t| relative.ends_with(&format!(".{t}")))
        || policy(relative) != Policy::Keep
        || runtime_owned(relative))
}

/// Where each key of the config's top-level object starts (1-based line, UTF-16 column), first
/// occurrence wins. Scans the JSONC text itself, skipping strings and comments, so a nested key
/// or a string value with the same text is never mistaken for it.
fn top_level_keys(text: &str) -> BTreeMap<String, (usize, usize)> {
    let mut keys = BTreeMap::new();
    let (mut depth, mut line, mut column) = (0usize, 1usize, 1usize);
    // The string just closed at depth 1 and where it started; a `:` next makes it a key.
    let mut pending: Option<(String, usize, usize)> = None;
    let mut chars = text.char_indices().peekable();
    let advance = |c: char, line: &mut usize, column: &mut usize| {
        if c == '\n' {
            *line += 1;
            *column = 1;
        } else {
            *column += c.len_utf16();
        }
    };
    while let Some((i, c)) = chars.next() {
        match c {
            '"' => {
                let (start_line, start_column) = (line, column);
                advance(c, &mut line, &mut column);
                let mut end = text.len();
                while let Some((j, s)) = chars.next() {
                    advance(s, &mut line, &mut column);
                    if s == '\\' {
                        if let Some((_, e)) = chars.next() {
                            advance(e, &mut line, &mut column);
                        }
                    } else if s == '"' {
                        end = j + 1;
                        break;
                    }
                }
                pending = (depth == 1)
                    .then(|| serde_json::from_str::<String>(&text[i..end]).ok())
                    .flatten()
                    .map(|key| (key, start_line, start_column));
                continue;
            }
            '/' if matches!(chars.peek(), Some((_, '/'))) => {
                for (_, s) in chars.by_ref() {
                    if s == '\n' {
                        advance(s, &mut line, &mut column);
                        break;
                    }
                }
                continue;
            }
            '/' if matches!(chars.peek(), Some((_, '*'))) => {
                advance(c, &mut line, &mut column);
                let mut previous = ' ';
                for (_, s) in chars.by_ref() {
                    advance(s, &mut line, &mut column);
                    if previous == '*' && s == '/' {
                        break;
                    }
                    previous = s;
                }
                continue;
            }
            ':' => {
                if let Some((key, l, col)) = pending.take() {
                    keys.entry(key).or_insert((l, col));
                }
            }
            '{' | '[' => {
                depth += 1;
                pending = None;
            }
            '}' | ']' => {
                depth = depth.saturating_sub(1);
                pending = None;
            }
            c if c.is_whitespace() => {}
            _ => pending = None,
        }
        advance(c, &mut line, &mut column);
    }
    keys
}

/// A capsule's files, capsule-relative, sorted.
#[derive(Default)]
struct Tree {
    files: Vec<PathBuf>,
    /// Symbolic links, never followed: one can point outside the project or at a credential.
    links: Vec<PathBuf>,
    /// Files on the source deny list as credentials, each file of a denied directory included.
    denied: Vec<PathBuf>,
}

fn walk(fs: &dyn Fs, root: &Path, dir: &Path, tree: &mut Tree, denied: bool) {
    let mut entries = fs.read_dir(dir).unwrap_or_default();
    entries.sort();
    for path in entries {
        let relative = path.strip_prefix(root).unwrap_or(&path).to_path_buf();
        let inside = match policy(&relative.to_string_lossy().replace('\\', "/")) {
            Policy::Skip if !denied => continue,
            Policy::Denied => true,
            _ => denied,
        };
        if fs.is_symlink(&path) {
            if inside { &mut tree.denied } else { &mut tree.links }.push(relative);
        } else if fs.is_dir(&path) {
            walk(fs, root, &path, tree, inside);
        } else if inside {
            tree.denied.push(relative);
        } else {
            tree.files.push(relative);
        }
    }
}

/// A finalize manifest records only the capsule's source (code in the source roots, pages,
/// package.json, env files), never the static files 0.8 served beside it. Those can't be checked
/// against the published version, so each one in the source tree is refused rather than carried
/// unchecked or dropped. `verified` lists the recorded paths, which the conversion sweeps itself.
pub(super) fn unverified(
    fs: &dyn Fs,
    root: &Path,
    verified: &std::collections::BTreeSet<String>,
    errors: &mut Vec<Failure>,
) {
    let mut tree = Tree::default();
    walk(fs, root, root, &mut tree, false);
    let name = |p: &PathBuf| p.to_string_lossy().replace('\\', "/");
    let at = |n: &str| format!("/{}", n.replace('~', "~0"));
    for path in tree.denied.iter().map(name).filter(|n| !verified.contains(n)) {
        errors.push(
            fail(
                &at(&path),
                format!("{DENIED}: {path} is on the source deny list, so the candidate leaves it out"),
            )
            .located(path.clone(), 1, 1),
        );
    }
    for link in tree.links.iter().map(name) {
        if SOURCE_ROOTS.contains(&link.split('/').next().unwrap_or("")) {
            continue;
        }
        errors.push(
            fail(
                &at(&link),
                format!("{SYMLINK}: {link} is a symbolic link, and the candidate copies only regular files inside the project"),
            )
            .located(link.clone(), 1, 1),
        );
    }
    // 0.8 applied its config and theme.json to the Space, and a manifest never records them, so
    // the candidate can't tell whether they are the published ones.
    let config = super::CONFIG_FILES.iter().chain(&[THEME_JSON, "spacefast.config.ts"]);
    for file in config.filter(|f| !verified.contains(**f)) {
        let path = root.join(file);
        if fs.is_file(&path) && !fs.is_symlink(&path) {
            errors.push(
                fail(
                    &at(file),
                    format!("{UNVERIFIED_CONFIG}: {file} is not in the finalize manifest, so the candidate can't check it is the published config"),
                )
                .located(file.to_string(), 1, 1),
            );
        }
    }
    for file in tree.files.iter().map(name).filter(|n| !verified.contains(n)) {
        // Unrecorded source was not part of the publish; unrecorded content may have been.
        let first = file.split('/').next().unwrap_or("");
        let content = RULE_FILES.contains(&file.as_str()) || first == "public" || served(&file);
        if SOURCE_ROOTS.contains(&first) || !content {
            continue;
        }
        errors.push(
            fail(
                &at(&file),
                format!("{UNVERIFIED}: {file} is not in the finalize manifest, so the candidate can't check it is the published file"),
            )
            .located(file.clone(), 1, 1),
        );
    }
}

/// The candidate's omissions. `converted_pages` lists the page files that converted or were
/// already refused (capsule paths); `client_carried` says whether `client/` was reproduced, as the
/// pages layout or as a converted client app (which then owns `public/`).
#[allow(clippy::too_many_arguments)]
pub(super) fn sweep(
    fs: &dyn Fs,
    root: &Path,
    config: Option<(&str, &serde_json::Value, &str)>,
    converted_pages: &[String],
    client_entry: Option<&Path>,
    client_carried: bool,
    client_app_converted: bool,
    declares_posts: bool,
    client_public: &[String],
    get_paths: &BTreeSet<String>,
    errors: &mut Vec<Failure>,
) -> Omitted {
    let mut out = Omitted {
        files: BTreeMap::new(),
        carried: vec![],
        config: None,
        kept: serde_json::Map::new(),
    };
    let mut tree = Tree::default();
    walk(fs, root, root, &mut tree, false);
    let Tree { files, links, denied } = tree;
    for path in &denied {
        let name = path.to_string_lossy().replace('\\', "/");
        errors.push(
            fail(
                &format!("/{}", name.replace('~', "~0")),
                format!("{DENIED}: {name} is on the source deny list, so the candidate leaves it out"),
            )
            .located(name.clone(), 1, 1),
        );
    }
    // A link inside a source root is the converters' to refuse; anywhere else it would be served.
    for link in &links {
        let name = link.to_string_lossy().replace('\\', "/");
        let first = name.split('/').next().unwrap_or("");
        if SOURCE_ROOTS.contains(&first) && first != "pages" {
            continue;
        }
        errors.push(
            fail(
                &format!("/{}", name.replace('~', "~0")),
                format!("{SYMLINK}: {name} is a symbolic link, and the candidate copies only regular files inside the project"),
            )
            .located(name.clone(), 1, 1),
        );
    }
    let mut client_reported = false;
    let mut posts_reported = false;
    // 0.8 built a client with no pages as the whole site and generated its index.html, shadowing
    // the author's (zero-compile's `standaloneClient`). The client is `runtime.client` wherever it
    // lives, as the client conversion resolves it.
    let client_app = client_entry.is_some()
        && !files
            .iter()
            .any(|f| f.starts_with("pages") && f.extension().is_some_and(|e| e == "tsx" || e == "md" || e == "html"));
    let client_root = client_entry
        .and_then(|e| e.iter().next())
        .map(|c| c.to_string_lossy().into_owned())
        .filter(|c| {
            !SOURCE_ROOTS.contains(&c.as_str()) && client_entry.is_some_and(|e| e.parent() != Some(Path::new("")))
        });
    for relative in files {
        let name = relative.to_string_lossy().replace('\\', "/");
        let at = format!("/{}", name.replace('~', "~0"));
        let first = name.split('/').next().unwrap_or("");
        let file = |e: Failure| e.located(name.clone(), 1, 1);
        // The client app's own directory (runtime.client outside client/), and its entry file.
        let in_client =
            first == "client" || client_root.as_deref() == Some(first) || client_entry.is_some_and(|e| relative == e);
        if in_client {
            // One error for the client: its files are one app, built by 0.8 into client.js.
            if !client_carried && !client_reported {
                client_reported = true;
                errors.push(fail("/client", format!("{CLIENT}: 0.8 built client/ into the Space's browser app beside its pages; only a client app without pages is converted, so publishing would remove this interface")).located(name.clone(), 1, 1));
            }
            continue;
        }
        // zero-compile's ZERO_STRUCTURAL_COLLECTIONS: without a declared `collections.posts`, 0.8
        // rebuilt content/posts/ into the Space's native posts on every publish (and also served the
        // files). The candidate adopts the existing post rows but doesn't rebuild them, so its posts
        // would stop following these files. A declared `posts` collection had no such default:
        // 0.8 only served the files, as the candidate does, and its `sync` has its own error.
        if name.starts_with("content/posts/") && !declares_posts {
            if !posts_reported {
                posts_reported = true;
                errors.push(file(fail(
                    "/content/posts",
                    format!("{POSTS}: 0.8 published content/posts/ as the Space's posts; the candidate keeps the existing posts but doesn't rebuild them from these files"),
                )));
            }
            continue;
        }
        if first == "pages" {
            let extension = relative.extension().and_then(|e| e.to_str()).unwrap_or("");
            // 0.8 routed .tsx, .md and .html; converted pages travel as v2 pages.
            if ["tsx", "md", "html"].contains(&extension) && !converted_pages.contains(&name) {
                errors.push(file(fail(
                    &at,
                    format!("{PAGES}: 0.8 served {name} as a page and the candidate has no counterpart"),
                )));
            }
            continue;
        }
        // A converted client app owns public/ and reports a clash there itself.
        if (name == "public" || name.starts_with("public/")) && client_app_converted {
            continue;
        }
        if name == "public" || name.starts_with("public/") {
            // 0.8 served public/ at /public/...; v2 serves public/ at the root, so the URLs move.
            errors.push(file(fail(
                &at,
                format!("{PUBLIC}: 0.8 served {name} at /{name}, and v2 serves public/ at the site root"),
            )));
            continue;
        }
        if RULE_FILES.contains(&name.as_str()) {
            errors.push(file(fail(
                &at,
                format!("{REDIRECTS}: 0.8 applied {name} to every request; v2 has no rule file"),
            )));
            continue;
        }
        if !served(&name) {
            continue;
        }
        // 0.8 generated index.html for a client app, so the author's own one was never served.
        if name == "index.html" && client_app {
            continue;
        }
        let reserved = crate::app::RESERVED_TP.contains(&first.to_ascii_lowercase().as_str());
        match fs.read_to_string(&root.join(&relative)) {
            Ok(text) if !reserved => {
                out.files.insert(format!("public/{name}"), text);
                out.carried.push(name);
            }
            Ok(_) => errors.push(file(fail(
                &at,
                format!("{STATIC}: /{first} belongs to WordPress or TypedPress on v2"),
            ))),
            // Binary files (images, fonts) need byte-exact copies, which the text candidate can't hold.
            Err(_) => errors.push(file(fail(
                &at,
                format!("{STATIC}: {name} is not UTF-8 text, and the candidate carries text files only"),
            ))),
        }
    }
    // The first segment of every file the candidate serves from public/, lowercased.
    let served: BTreeSet<String> = client_public
        .iter()
        .chain(out.files.keys())
        .filter_map(|p| p.strip_prefix("public/"))
        .filter_map(|p| p.split('/').next())
        .filter(|first| !first.is_empty())
        .map(str::to_ascii_lowercase)
        .collect();
    if let Some((config_file, config, text)) = config {
        // The key's line in the config file, where the fix goes.
        let keys = top_level_keys(text);
        let line_of = |key: &str| -> (usize, usize) { keys.get(key).copied().unwrap_or((1, 1)) };
        // Fail closed: every key 0.8 applied is refused unless it is known to change nothing.
        for (key, value) in config.as_object().into_iter().flatten() {
            if NO_OP_KEYS.contains(&key.as_str()) {
                continue;
            }
            let targets = Targets {
                get_paths,
                files: out.carried.iter().map(|name| format!("/{name}")).collect(),
            };
            if carries(key, value, &served, &targets) {
                out.kept.insert(key.clone(), value.clone());
                continue;
            }
            let reason = SERVING_KEYS
                .iter()
                .find(|(k, _)| k == key)
                .map_or(CONFIG, |(_, reason)| reason);
            let (line, column) = line_of(key);
            // Name the redirect that blocks the key, so the author knows which rule to move.
            let blocking = (key == "redirects")
                .then(|| value.as_array())
                .flatten()
                .and_then(|rules| rules.iter().find(|r| redirect_reaches_app(r, &served)))
                .and_then(|r| r.get("source"))
                .and_then(serde_json::Value::as_str)
                .map(|source| {
                    format!(
                        "; the rule on `{source}` can reach a path WordPress, Zero or the converted app answers on v2"
                    )
                })
                .unwrap_or_default();
            // Name the cron whose path the candidate doesn't answer.
            if key == "crons" {
                let (index, path) = targets.unanswered(value).unwrap_or_default();
                errors.push(
                    fail(
                        &pointer(&pointer(&format!("/{config_file}"), key), &index.to_string()),
                        format!(
                            "{CRONS}: `{path}` is not a GET endpoint or static file the candidate serves at that path"
                        ),
                    )
                    .located(config_file.to_string(), line, column),
                );
                continue;
            }
            errors.push(
                fail(
                    &pointer(&format!("/{config_file}"), key),
                    format!("{reason}: 0.8 applied {config_file} `{key}` to the Space{blocking}"),
                )
                .located(config_file.to_string(), line, column),
            );
        }
    }
    // Clean URLs need no error: the engine serves `<name>.html` at `/<name>` for the static files a
    // v2 version publishes from public/, by default as it did for 0.8's (`.htm` keeps its name on
    // both), and `cleanUrls` travels in sf.jsonc.
    // 0.8 inferred the pages' theme tokens from theme.json and linked the generated theme.css.
    if fs.is_file(&root.join(THEME_JSON)) {
        errors.push(
            fail(
                &format!("/{THEME_JSON}"),
                format!("{CONFIG}: 0.8 turned {THEME_JSON} into the Space's generated theme.css"),
            )
            .located(THEME_JSON.to_string(), 1, 1),
        );
    }
    out
}

#[cfg(test)]
mod tests {
    use super::redirect_reaches_app;

    /// A redirect is carried only when its first segment is a literal nothing on v2 answers.
    #[test]
    fn a_redirect_reaches_the_app_unless_its_first_segment_is_a_free_literal() {
        let served: std::collections::BTreeSet<String> = ["app", "tp", "client", "assets"].map(String::from).into();
        for (source, reaches) in [
            ("/", false),
            ("/old", false),
            ("/blog/:slug", false),
            // Any wp- first segment is WordPress territory, so even an unknown one is refused.
            ("/wp-jsonx/a", true),
            ("/wp-json/*", true),
            ("/WP-JSON/tp", true),
            ("/*", true),
            ("/:lang/*", true),
            ("/a*", true),
            // A capture inside a segment compiles to `[^/]+`, so these match /wp-json/... too.
            ("/w:x/*", true),
            ("/wp-:rest/*", true),
            ("/w:x", true),
            // The converted client app's modules and runtime live under these on v2.
            ("/app/*", true),
            ("/tp/client.js", true),
            // WordPress and Zero own these first segments on v2.
            ("/wp-admin/*", true),
            ("/wp-content/uploads/*", true),
            ("/typedpress-admin", true),
            ("/apps/old", false),
            // Any folder the candidate serves, read from its public/ tree, and WordPress's wp-*.
            ("/client/*", true),
            ("/assets/logo.png", true),
            ("/wp-comments-post.php", true),
            ("https://old.example/*", true),
            ("", true),
        ] {
            let rule = serde_json::json!({ "source": source, "destination": "/" });
            assert_eq!(redirect_reaches_app(&rule, &served), reaches, "{source}");
        }
        assert!(redirect_reaches_app(
            &serde_json::json!({ "destination": "/" }),
            &served
        ));
    }
}
