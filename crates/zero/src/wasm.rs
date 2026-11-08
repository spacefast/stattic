//! The compiler core for a browser: sources in memory, every generated text out, as JSON.
//!
//! The site's config builder calls [`compile`] through the exported C ABI (`zero_alloc`,
//! `zero_compile`, `zero_free`) from a `wasm32-unknown-unknown` build of this library with the
//! `cli` feature off: no file system, no network, no embedded runtime. The envelope is the one
//! `zero check --json` prints, so a config that checks here checks on the command line.
//!
//! Request:
//! ```json
//! { "name": "pantry",
//!   "files": { "zero.config.ts": "…", "lib/fields.ts": "…", "zero/abilities.json": "…",
//!              "zero/plugins.lock.json": "…" },,
//!   "previous": null, "migrationFiles": [], "frozen": false }
//! ```
//! `previous` is the last build's `zero/migrations/snapshot.json` (null for a first build) and
//! `migrationFiles` the names beside it, which number the next migration like `zero build` does.
//! `files` carries every compiler input, including the committed `zero/abilities.json` lock that
//! types `abilities.call` from "php/wordpress".
//! `zero/plugins.lock.json` grants plugin packages, and `frozen` refuses grants beyond it, as
//! `zero check --frozen` (or `CI`) does.
//! Directories exist as the parents of files: a project in memory has no empty directory, and git
//! cannot hold one either, so that is the one shape of project the disk can have and this cannot.
//!
//! Response: `{ "check": <zero-diagnostics envelope>, "output": null }` on a diagnostic, otherwise
//! `output` carries `schemaPhp`, `functionsPhp`, `migrations` (`[name, sql]` pairs), `snapshot`,
//! `client` (`types.ts` and `schema.ts`), `genTs`, `warnings`, `unsupportedPlugins` and `hints`.

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
    #[serde(default)]
    previous: Option<serde_json::Value>,
    #[serde(default)]
    migration_files: Vec<String>,
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
    unsupported_plugins: Vec<String>,
    hints: Vec<String>,
}

#[derive(Serialize)]
struct Response<'a> {
    check: diag::Envelope<'a>,
    output: Option<Output>,
}

/// Sources by absolute path under one project directory.
pub(crate) struct Memory {
    files: BTreeMap<PathBuf, String>,
}

/// Reads like Linux's ENOENT, so the envelope for a missing file is the command line's.
fn not_found() -> std::io::Error {
    std::io::Error::new(std::io::ErrorKind::NotFound, "No such file or directory (os error 2)")
}

impl Memory {
    pub(crate) fn new(files: BTreeMap<PathBuf, String>) -> Self {
        Memory { files }
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
        self.files.contains_key(key)
    }

    fn dir_at(&self, key: &Path) -> bool {
        self.files.keys().any(|file| file.starts_with(key) && file != key)
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
        for file in self.files.keys() {
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
    let files = request
        .files
        .into_iter()
        .map(|(rel, source)| (Memory::key(&project.join(rel)), source))
        .collect();
    let frozen = request.frozen;
    let previous = || {
        let mut previous =
            pipeline::Previous::from_project(request.previous, request.migration_files.iter().map(String::as_str))?;
        previous.frozen = frozen;
        Ok(previous)
    };
    let entry = project.join("zero.config.ts");
    let built = match loader::load_from(Rc::new(Memory::new(files)), &entry) {
        Err(diagnostics) => Err(diagnostics),
        Ok(program) => pipeline::build(&project, &program, previous, |_| None).map_err(|d| vec![d]),
    };
    let (diagnostics, output) = match built {
        Err(diagnostics) => (diagnostics, None),
        Ok(built) => {
            let client = built
                .client_files
                .into_iter()
                .map(|(name, bytes)| (name, String::from_utf8_lossy(&bytes).into_owned()))
                .collect();
            let output = Output {
                schema_php: crate::schema_php::render(&built.schema),
                functions_php: built.functions_php,
                migrations: built.migrations,
                snapshot: built.snapshot,
                client,
                gen_ts: built.gen_ts,
                warnings: built.warnings,
                unsupported_plugins: built.unsupported_plugins,
                hints: built.hints,
            };
            (Vec::new(), Some(output))
        }
    };
    let response = Response {
        check: diag::envelope(&diagnostics, &project),
        output,
    };
    serde_json::to_string(&response).expect("response serializes")
}

/// Filesystem operations accept either an absolute `project` on the host or a `files`
/// map relative to it. Migration returns a candidate file map; the caller owns writes.
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
                "migrateApp" => Ok(serde_json::to_value(crate::config_migrate::app::migrate(
                    fs,
                    &project,
                    request["space"].as_str(),
                    request["users"].as_bool().unwrap_or(false),
                ))
                .unwrap()),
                "migrateConfig" => {
                    let source = crate::config_migrate::Source::parse(request["source"].as_str().unwrap_or("zero"))
                        .ok_or("unknown source CMS")?;
                    let path = project.join(request["input"].as_str().unwrap_or("capsule.ts"));
                    let text = fs.read_to_string(&path).map_err(|e| e.to_string())?;
                    let mut entries = vec![];
                    Ok(
                        match crate::config_migrate::convert(source, &path, &text, &mut entries) {
                            Ok(config) => {
                                serde_json::json!({"status":"candidate", "entries":entries,"errors":[],"files":{"zero.config.ts":config}})
                            }
                            Err(e) => {
                                serde_json::json!({"status":"refused", "entries":entries,"errors":[e],"files":{}})
                            }
                        },
                    )
                }
                "check" => {
                    let root = fs.canonicalize(&project).map_err(|e| e.to_string())?;
                    let previous = root.join("zero/migrations/snapshot.json");
                    let built = loader::load_from(fs.clone(), &root.join("zero.config.ts")).and_then(|program| {
                        pipeline::build(
                            &root,
                            &program,
                            || {
                                let snapshot = if fs.exists(&previous) {
                                    Some(
                                        serde_json::from_str(
                                            &fs.read_to_string(&previous)
                                                .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?,
                                        )
                                        .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?,
                                    )
                                } else {
                                    None
                                };
                                let dir = root.join("zero/migrations");
                                let paths = if fs.is_dir(&dir) {
                                    fs.read_dir(&dir)
                                        .map_err(|e| diag::Diag::new(diag::Span::NONE, e.to_string()))?
                                } else {
                                    vec![]
                                };
                                let names: Vec<_> = paths
                                    .iter()
                                    .map(|p| p.file_name().unwrap_or_default().to_string_lossy())
                                    .collect();
                                let mut previous =
                                    pipeline::Previous::from_project(snapshot, names.iter().map(|s| s.as_ref()))?;
                                previous.frozen = request["frozen"].as_bool().unwrap_or(false);
                                Ok(previous)
                            },
                            |_| None,
                        )
                        .map_err(|d| vec![d])
                    });
                    let diagnostics = built.err().unwrap_or_default();
                    Ok(serde_json::json!({"check":diag::envelope(&diagnostics, &root)}))
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
    pub extern "C" fn zero_alloc(len: usize) -> *mut u8 {
        let mut buffer = Vec::<u8>::with_capacity(len);
        let ptr = buffer.as_mut_ptr();
        std::mem::forget(buffer);
        ptr
    }

    /// Frees a buffer from `zero_alloc` or `zero_compile` (`len` is the length it was handed out with).
    ///
    /// # Safety
    /// `ptr` and `len` must come from one of those two functions and not have been freed already.
    #[unsafe(no_mangle)]
    pub unsafe extern "C" fn zero_free(ptr: *mut u8, len: usize) {
        drop(unsafe { Vec::from_raw_parts(ptr, 0, len) });
    }

    /// Compiles the UTF-8 request at `ptr..ptr+len`, taking ownership of that buffer. Returns a buffer
    /// whose first four bytes are the little-endian length of the JSON response that follows; free it
    /// with `zero_free(ptr, 4 + len)`.
    ///
    /// # Safety
    /// `ptr..ptr+len` must be a `zero_alloc(len)` buffer the caller has filled.
    #[unsafe(no_mangle)]
    pub unsafe extern "C" fn zero_compile(ptr: *mut u8, len: usize) -> *mut u8 {
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
            /// The input must be an owned `zero_alloc(len)` buffer.
            #[unsafe(no_mangle)]
            pub unsafe extern "C" fn $name(ptr: *mut u8, len: usize) -> *mut u8 {
                unsafe { respond(ptr, len, |request| super::operation($operation, request)) }
            }
        };
    }
    operation!(zero_sources, "sources");
    operation!(zero_check, "check");
    operation!(zero_migrate_app, "migrateApp");
    operation!(zero_migrate_config, "migrateConfig");
}

#[cfg(test)]
mod tests {
    use super::*;

    fn memory() -> Memory {
        Memory::new(
            [("/p/zero.config.ts", ""), ("/p/lib.ts", ""), ("/p/dir/util.ts", "")]
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
        compile(r#"{"name":"p","files":{"zero.config.ts":"export default 1;"}}"#);
        assert_eq!(rendered(), expected);
    }

    /// The browser types `abilities.call` from the lock it is sent, exactly as the command line does.
    #[test]
    fn a_locked_ability_in_the_request_types_its_call() {
        let config = r#"import { action, defineConfig } from "zero";
import { abilities } from "php/wordpress";
export default defineConfig({ actions: { a: action({ input: {}, access: "anyone", handler: () => abilities.call("acme/info", { field: "title" }) }) } });"#;
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
            message(serde_json::json!({"zero.config.ts": config, "zero/abilities.json": lock.to_string()})),
            "`acme/info` takes { field: \"name\" }, not { field: \"title\" }"
        );
        assert_eq!(
            message(serde_json::json!({"zero.config.ts": config})),
            "unknown ability `acme/info`"
        );
    }
}
