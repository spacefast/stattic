//! The compiler core for a browser: sources in memory, every generated text out, as JSON.
//!
//! The site's config builder calls [`compile`] through the exported C ABI (`tp_alloc`,
//! `tp_compile`, `tp_free`) from a `wasm32-unknown-unknown` build of this library with the
//! `cli` feature off: no file system, no network, no embedded runtime. The envelope is the one
//! `typedpress check --json` prints, so a config that checks here checks on the command line.
//!
//! Request:
//! ```json
//! { "name": "pantry",
//!   "files": { "typedpress.config.ts": "…", "tp/lib/fields.ts": "…", "tp/_generated/abilities.json": "…",
//!              "tp/_generated/plugins.lock.json": "…" },,
//!   "frozen": false }
//! ```
//! Pass committed migration folders under `tp/_generated/migrations/` in `files` so the compiler
//! can fold the same history as `typedpress build`.
//! `files` carries every compiler input, including the committed `tp/_generated/abilities.json` lock that
//! types `abilities.call` from "php/wordpress".
//! `tp/_generated/plugins.lock.json` grants plugin packages, and `frozen` refuses grants beyond it, as
//! `typedpress check --frozen` (or `CI`) does.
//! Directories exist as the parents of files: a project in memory has no empty directory, and git
//! cannot hold one either, so that is the one shape of project the disk can have and this cannot.
//!
//! Response: `{ "check": <typedpress-diagnostics envelope>, "output": null }` on a diagnostic, otherwise
//! `output` carries `schemaPhp`, `functionsPhp`, `migrations` (`[relativePath, text]` pairs), `snapshot`,
//! `client` (`types.ts` and `schema.ts`), `genTs`, `warnings`, `unsupportedPlugins`, `hints`,
//! `appPlugin` (every file of the `typedpress-app-<name>` plugin `typedpress build` writes, by path below the
//! plugins directory: the migration folders under `tp/_generated/migrations/` in `files` and this
//! compile's new ones; its `tp/app/openapi.json` is what `typedpress build` also writes to
//! `tp/_generated/openapi.json`), `tpPlugins` (TypedPress's plugins in activation
//! order) and `wordpressPlugins` (`{slug, version}`, dependencies first).

use crate::loader::{self, Fs};
use crate::{diag, pipeline};
use serde::{Deserialize, Serialize};
use std::collections::BTreeMap;
use std::path::{Component, Path, PathBuf};
use std::rc::Rc;

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Request {
    name: String,
    files: BTreeMap<String, String>,
    /// Optional binary files (e.g. imported PNG/WebP/WOFF2), base64-encoded.
    #[serde(default)]
    binary: BTreeMap<String, String>,
    #[serde(default)]
    frozen: bool,
}

#[derive(Serialize)]
#[serde(rename_all = "camelCase")]
struct Output {
    schema_php: String,
    functions_php: String,
    migrations: Vec<(String, String)>,
    snapshot: serde_json::Value,
    client: BTreeMap<String, String>,
    gen_ts: String,
    warnings: Vec<String>,
    lint_warnings: Vec<crate::lint::Warning>,
    unsupported_plugins: Vec<String>,
    hints: Vec<String>,
    /// The app plugin `typedpress build` writes, file by file, by path below the plugins directory.
    app_plugin: BTreeMap<String, String>,
    /// Non-UTF-8 binary files of the app plugin (e.g. PNG/WebP/WOFF2), base64-encoded.
    #[serde(default, skip_serializing_if = "BTreeMap::is_empty")]
    app_plugin_binary: BTreeMap<String, String>,
    /// TypedPress's plugins for this app, in activation order.
    tp_plugins: Vec<String>,
    /// The WordPress.org plugins the app needs, dependencies first.
    wordpress_plugins: Vec<WordPressPlugin>,
}

#[derive(Serialize)]
struct WordPressPlugin {
    slug: String,
    version: String,
}

#[derive(Serialize)]
struct Response<'a> {
    check: diag::Envelope<'a>,
    output: Option<Output>,
}

/// Sources by absolute path under one project directory.
pub struct Memory {
    files: BTreeMap<PathBuf, String>,
    binary: BTreeMap<PathBuf, Vec<u8>>,
}

/// Reads like Linux's ENOENT, so the envelope for a missing file is the command line's.
fn not_found() -> std::io::Error {
    std::io::Error::new(std::io::ErrorKind::NotFound, "No such file or directory (os error 2)")
}

impl Memory {
    pub fn new(files: BTreeMap<PathBuf, String>) -> Self {
        Memory {
            files,
            binary: BTreeMap::new(),
        }
    }

    pub(crate) fn with_binary(files: BTreeMap<PathBuf, String>, binary: BTreeMap<PathBuf, Vec<u8>>) -> Self {
        Memory { files, binary }
    }

    /// Plain keys: absolute, no `.` or `..`, as the request's paths are joined.
    pub(crate) fn key(path: &Path) -> PathBuf {
        let mut out = PathBuf::from("/");
        for part in path.components() {
            match part {
                Component::RootDir | Component::Prefix(_) | Component::CurDir => {}
                Component::ParentDir => {
                    out.pop();
                }
                Component::Normal(name) => out.push(name),
            }
        }
        out
    }

    fn file_at(&self, key: &Path) -> bool {
        self.files.contains_key(key) || self.binary.contains_key(key)
    }

    fn dir_at(&self, key: &Path) -> bool {
        self.files
            .keys()
            .chain(self.binary.keys())
            .any(|file| file.starts_with(key) && file != key)
    }

    /// Walks `path` the way the operating system does: every component but the last must be a
    /// directory that exists, so `./absent/../lib` and `./lib.ts/../lib` are not found even though
    /// they name an existing file once `..` is folded, and a trailing `/` names a directory, so
    /// `data.json/` is nothing. The result may or may not exist.
    fn resolve(&self, path: &Path) -> Option<PathBuf> {
        let text = path.to_str()?;
        let wants_dir = text.ends_with('/') || text.ends_with("/.");
        let mut out = PathBuf::from("/");
        for part in path.components() {
            let step = match part {
                Component::RootDir | Component::Prefix(_) | Component::CurDir => continue,
                Component::ParentDir => None,
                Component::Normal(name) => Some(name),
            };
            if out != Path::new("/") && !self.dir_at(&out) {
                return None;
            }
            match step {
                None => {
                    out.pop();
                }
                Some(name) => out.push(name),
            }
        }
        if wants_dir && !self.dir_at(&out) {
            return None;
        }
        Some(out)
    }
}

impl Fs for Memory {
    fn read_dir(&self, path: &Path) -> std::io::Result<Vec<PathBuf>> {
        let key = self.canonicalize(path)?;
        if !self.is_dir(&key) {
            return Err(std::io::Error::other("not a directory"));
        }
        let mut entries = std::collections::BTreeSet::new();
        for file in self.files.keys().chain(self.binary.keys()) {
            if let Ok(relative) = file.strip_prefix(&key)
                && let Some(component) = relative.components().next()
            {
                entries.insert(key.join(component));
            }
        }
        Ok(entries.into_iter().collect())
    }

    fn read_to_string(&self, path: &Path) -> std::io::Result<String> {
        let key = self.resolve(path).ok_or_else(not_found)?;
        if let Some(source) = self.files.get(&key) {
            return Ok(source.clone());
        }
        if let Some(bytes) = self.binary.get(&key) {
            return String::from_utf8(bytes.clone())
                .map_err(|e| std::io::Error::new(std::io::ErrorKind::InvalidData, e));
        }
        if self.dir_at(&key) {
            return Err(std::io::Error::new(
                std::io::ErrorKind::IsADirectory,
                "Is a directory (os error 21)",
            ));
        }
        Err(not_found())
    }

    fn read(&self, path: &Path) -> std::io::Result<Vec<u8>> {
        let key = self.resolve(path).ok_or_else(not_found)?;
        if let Some(bytes) = self.binary.get(&key) {
            return Ok(bytes.clone());
        }
        if let Some(source) = self.files.get(&key) {
            return Ok(source.as_bytes().to_vec());
        }
        if self.dir_at(&key) {
            return Err(std::io::Error::new(
                std::io::ErrorKind::IsADirectory,
                "Is a directory (os error 21)",
            ));
        }
        Err(not_found())
    }
    fn is_file(&self, path: &Path) -> bool {
        self.resolve(path).is_some_and(|key| self.file_at(&key))
    }
    fn is_dir(&self, path: &Path) -> bool {
        self.resolve(path).is_some_and(|key| self.dir_at(&key))
    }
    fn exists(&self, path: &Path) -> bool {
        self.resolve(path)
            .is_some_and(|key| self.file_at(&key) || self.dir_at(&key))
    }
    fn canonicalize(&self, path: &Path) -> std::io::Result<PathBuf> {
        let key = self.resolve(path).ok_or_else(not_found)?;
        if self.file_at(&key) || self.dir_at(&key) {
            Ok(key)
        } else {
            Err(not_found())
        }
    }
}

/// One compile of a request (see the module documentation); always answers with JSON.
pub fn compile(request: &str) -> String {
    let request: Request = match serde_json::from_str(request) {
        Ok(request) => request,
        Err(e) => return serde_json::json!({ "error": format!("invalid request: {e}") }).to_string(),
    };
    diag::isolated(|| compile_request(request))
}

fn compile_request(request: Request) -> String {
    let project = PathBuf::from("/").join(&request.name);
    // The project's `public/` and the ledger's SQL go into the app plugin, as `typedpress build` copies them.
    let under = |prefix: &str| -> Vec<(String, Vec<u8>)> {
        request
            .files
            .iter()
            .filter_map(|(rel, text)| Some((rel.strip_prefix(prefix)?.to_string(), text.clone().into_bytes())))
            .collect()
    };
    let mut public = under("public/");
    let ledger = under(&format!("{}/", pipeline::LEDGER));
    let mut prev_entries = Vec::new();
    for (rel, bytes) in &ledger {
        if let Some((id, file)) = rel.split_once('/')
            && file == "ops.json"
            && !id.is_empty()
            && !file.contains('/')
        {
            prev_entries.push((id.to_string(), bytes.clone()));
        }
    }
    let mut binary: BTreeMap<PathBuf, Vec<u8>> = BTreeMap::new();
    for (rel, b64) in request.binary {
        let Some(bytes) = crate::assets::base64_decode(&b64) else {
            return serde_json::json!({ "error": format!("invalid base64 for {rel}") }).to_string();
        };
        if let Some(pub_rel) = rel.strip_prefix("public/") {
            public.push((pub_rel.to_string(), bytes.clone()));
        }
        binary.insert(Memory::key(&project.join(rel)), bytes);
    }
    let files = request
        .files
        .into_iter()
        .map(|(rel, source)| (Memory::key(&project.join(rel)), source))
        .collect();
    let frozen = request.frozen;
    let previous = || {
        let mut parsed = Vec::with_capacity(prev_entries.len());
        for (id, bytes) in prev_entries {
            let ops: serde_json::Value = serde_json::from_slice(&bytes).map_err(|e| {
                diag::Diag::new(
                    diag::Span::NONE,
                    format!("{}/{id}/ops.json is not valid JSON: {e}", pipeline::LEDGER),
                )
            })?;
            parsed.push((id, ops));
        }
        let mut previous = pipeline::Previous::from_entries(parsed)?;
        previous.frozen = frozen;
        Ok(previous)
    };
    let entry = project.join("typedpress.config.ts");
    let built = match loader::load_from(Rc::new(Memory::with_binary(files, binary)), &entry) {
        Err(diagnostics) => Err(diagnostics),
        // This writes the app plugin: a stylesheet the frontend build has not built yet is refused.
        Ok(program) => crate::assets::require_frontend_styles(&program, |_| true)
            .and_then(|()| pipeline::build(&project, &program, previous, |_| None))
            .map_err(|d| vec![d]),
    };
    let mut snapshot = None;
    let (diagnostics, output) = match built {
        Err(diagnostics) => (diagnostics, None),
        Ok(built) => {
            snapshot = Some(built.snapshot.clone());
            // The ledger's migration folders from the request, then this compile's new folders,
            // as `typedpress build` writes the new ones into the ledger and packs all of it.
            let mut new_migration_files: Vec<(String, String)> = Vec::new();
            for entry in &built.migrations {
                new_migration_files.push((
                    format!("{}/ops.json", entry.id),
                    serde_json::to_string_pretty(&entry.ops).expect("ops serialize") + "\n",
                ));
                new_migration_files.push((format!("{}/migration.sql", entry.id), entry.sql.clone()));
            }
            let migrations = ledger.into_iter().chain(
                new_migration_files
                    .iter()
                    .map(|(rel, text)| (rel.clone(), text.clone().into_bytes())),
            );
            let (schema_php, packaged) = crate::app_plugin::package(&built, migrations, public);
            let mut app_plugin = BTreeMap::new();
            let mut app_plugin_binary = BTreeMap::new();
            for (path, bytes) in packaged {
                match String::from_utf8(bytes) {
                    Ok(text) => {
                        app_plugin.insert(path, text);
                    }
                    Err(err) => {
                        app_plugin_binary.insert(path, crate::assets::base64_encode(err.as_bytes()));
                    }
                }
            }
            let empty = crate::wp_plugins::Config { plugins: Vec::new() };
            // The config's plugins resolved when it was extracted; this repeats that plan for its order.
            let wordpress_plugins = crate::wp_plugins::resolve(
                built.plugins.as_ref().unwrap_or(&empty),
                &crate::platform::plugins(built.schema.locales.is_some()),
            )
            .map(|plan| plan.plugins)
            .unwrap_or_default()
            .into_iter()
            .map(|p| WordPressPlugin {
                slug: p.slug,
                version: p.version,
            })
            .collect();
            let client = built
                .client_files
                .into_iter()
                .map(|(name, bytes)| (name, String::from_utf8_lossy(&bytes).into_owned()))
                .collect();
            let output = Output {
                tp_plugins: crate::app_plugin::tp_plugins(&built.schema),
                app_plugin,
                app_plugin_binary,
                wordpress_plugins,
                schema_php,
                functions_php: built.functions_php,
                migrations: new_migration_files,
                snapshot: built.snapshot,
                client,
                gen_ts: built.gen_ts,
                warnings: built.warnings,
                lint_warnings: built.lint_warnings,
                unsupported_plugins: built.unsupported_plugins,
                hints: built.hints,
            };
            (Vec::new(), Some(output))
        }
    };
    let mut check = diag::envelope(&diagnostics, &project);
    if let Some(snapshot) = &snapshot {
        check = check.with_indexes(snapshot);
    }
    let response = Response { check, output };
    serde_json::to_string(&response).expect("response serializes")
}

/// Filesystem operations accept either an absolute `project` on the host or a `files`
/// map relative to it.
pub fn operation(operation: &str, request: &str) -> String {
    diag::isolated(|| {
        let result = (|| -> Result<serde_json::Value, String> {
            let request: serde_json::Value = serde_json::from_str(request).map_err(|e| e.to_string())?;
            let project = PathBuf::from(request["project"].as_str().unwrap_or("/project"));
            if !project.has_root() {
                return Err("project must be absolute".into());
            }
            let fs: Rc<dyn Fs> = if let Some(files) = request.get("files") {
                let files: BTreeMap<String, String> =
                    serde_json::from_value(files.clone()).map_err(|e| e.to_string())?;
                let mut absolute = BTreeMap::new();
                for (path, text) in files {
                    if Path::new(&path).has_root()
                        || path.split('/').any(|p| p == ".." || p.is_empty())
                        || path.contains('\\')
                    {
                        return Err("invalid relative source path".into());
                    }
                    absolute.insert(project.join(path), text);
                }
                Rc::new(Memory::new(absolute))
            } else {
                #[cfg(target_arch = "wasm32")]
                {
                    Rc::new(crate::wasm_host_fs::Host)
                }
                #[cfg(not(target_arch = "wasm32"))]
                {
                    Rc::new(loader::Disk)
                }
            };
            match operation {
                "sources" => {
                    match crate::bundle::capture(fs, &project, request["packages"].as_bool().unwrap_or(true)) {
                        Ok(output) => Ok(serde_json::to_value(output).unwrap()),
                        Err(d) => Ok(serde_json::json!({"check":diag::envelope(&[d], &project), "output":null})),
                    }
                }
                "check" => {
                    let root = fs.canonicalize(&project).map_err(|e| e.to_string())?;
                    let built = loader::load_from(fs.clone(), &root.join("typedpress.config.ts")).and_then(|program| {
                        pipeline::build(
                            &root,
                            &program,
                            || {
                                if fs.exists(&root.join(pipeline::OLD_LEDGER)) {
                                    return Err(pipeline::moved_ledger());
                                }
                                let dir = root.join(pipeline::LEDGER);
                                let mut entries = Vec::new();
                                if fs.is_dir(&dir) {
                                    for path in fs
                                        .read_dir(&dir)
                                        .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?
                                    {
                                        if fs.is_dir(&path) {
                                            let id =
                                                path.file_name().unwrap_or_default().to_string_lossy().into_owned();
                                            let text = fs
                                                .read_to_string(&path.join("ops.json"))
                                                .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?;
                                            let ops: serde_json::Value = serde_json::from_str(&text)
                                                .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?;
                                            entries.push((id, ops));
                                        }
                                    }
                                }
                                let mut previous = pipeline::Previous::from_entries(entries)?;
                                previous.frozen = request["frozen"].as_bool().unwrap_or(false);
                                Ok(previous)
                            },
                            |_| None,
                        )
                        .map_err(|d| vec![d])
                    });
                    let check = match built {
                        Ok(built) => diag::envelope(&[], &root).with_indexes(&built.snapshot),
                        Err(diagnostics) => {
                            return Ok(serde_json::json!({"check":diag::envelope(&diagnostics, &root)}));
                        }
                    };
                    Ok(serde_json::json!({ "check": check }))
                }
                _ => Err("unknown operation".into()),
            }
        })();
        match result {
            Ok(value) => value.to_string(),
            Err(error) => serde_json::json!({"error":error}).to_string(),
        }
    })
}

/// The C ABI the browser glue uses: a request buffer in, a length-prefixed response buffer out.
#[cfg(target_arch = "wasm32")]
mod abi {
    /// A buffer of `len` bytes for the caller to write the request into.
    #[unsafe(no_mangle)]
    pub extern "C" fn tp_alloc(len: usize) -> *mut u8 {
        let mut buffer = Vec::<u8>::with_capacity(len);
        let ptr = buffer.as_mut_ptr();
        std::mem::forget(buffer);
        ptr
    }

    /// Frees a buffer from `tp_alloc` or `tp_compile` (`len` is the length it was handed out with).
    ///
    /// # Safety
    /// `ptr` and `len` must come from one of those two functions and not have been freed already.
    #[unsafe(no_mangle)]
    pub unsafe extern "C" fn tp_free(ptr: *mut u8, len: usize) {
        drop(unsafe { Vec::from_raw_parts(ptr, 0, len) });
    }

    /// Compiles the UTF-8 request at `ptr..ptr+len`, taking ownership of that buffer. Returns a buffer
    /// whose first four bytes are the little-endian length of the JSON response that follows; free it
    /// with `tp_free(ptr, 4 + len)`.
    ///
    /// # Safety
    /// `ptr..ptr+len` must be a `tp_alloc(len)` buffer the caller has filled.
    #[unsafe(no_mangle)]
    pub unsafe extern "C" fn tp_compile(ptr: *mut u8, len: usize) -> *mut u8 {
        unsafe { respond(ptr, len, super::compile) }
    }

    unsafe fn respond(ptr: *mut u8, len: usize, run: impl FnOnce(&str) -> String) -> *mut u8 {
        let request = unsafe { Vec::from_raw_parts(ptr, len, len) };
        let response = match std::str::from_utf8(&request) {
            Ok(request) => run(request),
            Err(_) => serde_json::json!({"error":"request is not UTF-8"}).to_string(),
        };
        let mut out = Vec::with_capacity(4 + response.len());
        out.extend_from_slice(&(response.len() as u32).to_le_bytes());
        out.extend_from_slice(response.as_bytes());
        let ptr = out.as_mut_ptr();
        std::mem::forget(out);
        ptr
    }
    macro_rules! operation {
        ($name:ident, $operation:literal) => {
            /// # Safety
            /// The input must be an owned `tp_alloc(len)` buffer.
            #[unsafe(no_mangle)]
            pub unsafe extern "C" fn $name(ptr: *mut u8, len: usize) -> *mut u8 {
                unsafe { respond(ptr, len, |request| super::operation($operation, request)) }
            }
        };
    }
    operation!(tp_sources, "sources");
    operation!(tp_check, "check");
}

#[cfg(test)]
mod tests {
    use super::*;

    fn memory() -> Memory {
        Memory::new(
            [
                ("/p/typedpress.config.ts", ""),
                ("/p/lib.ts", ""),
                ("/p/dir/util.ts", ""),
            ]
            .into_iter()
            .map(|(path, source)| (PathBuf::from(path), source.to_string()))
            .collect(),
        )
    }

    #[test]
    fn a_path_through_a_missing_or_file_component_is_not_found_like_on_disk() {
        let fs = memory();
        assert!(!fs.is_file(Path::new("/p/absent/../lib.ts")));
        assert!(!fs.is_file(Path::new("/p/lib.ts/../lib.ts")));
        assert!(fs.canonicalize(Path::new("/p/dir/../lib.ts")).is_ok());
        assert!(fs.is_file(Path::new("/p/./lib.ts")));
    }

    #[test]
    fn reading_a_directory_fails_the_way_the_operating_system_says_it() {
        let fs = memory();
        assert_eq!(
            fs.read_to_string(Path::new("/p/dir")).unwrap_err().to_string(),
            "Is a directory (os error 21)"
        );
        assert_eq!(
            fs.read_to_string(Path::new("/p/none.ts")).unwrap_err().to_string(),
            "No such file or directory (os error 2)"
        );
    }

    #[test]
    fn a_trailing_slash_names_a_directory_so_a_file_with_one_is_nothing() {
        let fs = memory();
        assert!(!fs.is_file(Path::new("/p/lib.ts/")));
        assert!(!fs.exists(Path::new("/p/lib.ts/")));
        assert!(fs.canonicalize(Path::new("/p/lib.ts/")).is_err());
        assert!(fs.is_dir(Path::new("/p/dir/")));
        assert_eq!(fs.canonicalize(Path::new("/p/dir/.")).unwrap(), PathBuf::from("/p/dir"));
    }

    #[test]
    fn directories_are_implied_by_the_files_under_them() {
        let fs = memory();
        assert!(fs.is_dir(Path::new("/p/dir")));
        assert!(fs.exists(Path::new("/p/dir")));
        assert!(!fs.is_file(Path::new("/p/dir")));
        assert!(!fs.is_dir(Path::new("/p/di")));
        assert_eq!(fs.canonicalize(Path::new("/p/dir")).unwrap(), PathBuf::from("/p/dir"));
        assert_eq!(
            fs.canonicalize(Path::new("/p/nothing")).unwrap_err().kind(),
            std::io::ErrorKind::NotFound
        );
    }

    #[test]
    fn a_compile_leaves_the_callers_registered_sources_alone() {
        let before = diag::register_file(Path::new("/outer.ts"), "let x = 1;\n");
        let rendered = || {
            diag::render(
                &diag::Diag::new(
                    diag::Span {
                        file: before,
                        start: 4,
                        end: 5,
                    },
                    "here",
                ),
                Path::new("/"),
            )
        };
        let expected = rendered();
        compile(r#"{"name":"p","files":{"typedpress.config.ts":"export default 1;"}}"#);
        assert_eq!(rendered(), expected);
    }

    #[test]
    fn the_browser_packages_public_script_versions() {
        let source = "export const message = 'hello';\n";
        let request = serde_json::json!({
            "name": "packaged",
            "files": {
                "typedpress.config.ts": "import { defineConfig } from 'typedpress'; export default defineConfig({ name: 'packaged' });",
                "public/modules/example.mjs": source,
                "public/image.svg": "<svg/>",
            },
        });
        let response: serde_json::Value = serde_json::from_str(&compile(&request.to_string())).unwrap();
        let output = &response["output"];
        let functions = output["functionsPhp"].as_str().unwrap_or_else(|| panic!("{response}"));
        let plugin = &output["appPlugin"];
        assert_eq!(plugin["typedpress-app-packaged/tp/app/functions.php"], functions);
        assert_eq!(plugin["typedpress-app-packaged/public/modules/example.mjs"], source);
        let map = plugin["typedpress-app-packaged/tp/app/public-files.json"]
            .as_str()
            .unwrap();
        use sha2::Digest;
        let version = crate::hex(&sha2::Sha256::digest(source)[..6]);
        let svg_version = crate::hex(&sha2::Sha256::digest("<svg/>")[..6]);
        assert_eq!(
            serde_json::from_str::<serde_json::Value>(map).unwrap(),
            serde_json::json!({
                "image.svg": svg_version,
                "modules/example.mjs": version,
            })
        );
        assert!(!functions.contains(&version));
    }

    /// The browser types `abilities.call` from the lock it is sent, exactly as the command line does.
    #[test]
    fn a_locked_ability_in_the_request_types_its_call() {
        let config = r#"import { query, defineConfig } from "typedpress";
import { abilities } from "php/wordpress";
const __a = query({ access: "anyone" }, () => abilities.call("acme/info", { field: "title" })); export { __a as a }; export default defineConfig({});"#;
        let lock = serde_json::json!({"version": 1, "abilities": [{"name": "acme/info",
            "input_schema": {"type": "object", "properties": {"field": {"enum": ["name"]}}, "required": ["field"]}, "output_schema": {}}]});
        let message = |files: serde_json::Value| {
            let response: serde_json::Value =
                serde_json::from_str(&compile(&serde_json::json!({"name": "p", "files": files}).to_string())).unwrap();
            response["check"]["diagnostics"][0]["message"]
                .as_str()
                .unwrap()
                .to_string()
        };
        assert_eq!(
            message(serde_json::Value::Object(serde_json::Map::from_iter([
                ("typedpress.config.ts".to_string(), config.into()),
                (crate::wordpress::abilities::LOCK.to_string(), lock.to_string().into()),
            ]))),
            "`acme/info` takes { field: \"name\" }, not { field: \"title\" }"
        );
        assert_eq!(
            message(serde_json::json!({"typedpress.config.ts": config})),
            "unknown ability `acme/info`"
        );
    }
}
