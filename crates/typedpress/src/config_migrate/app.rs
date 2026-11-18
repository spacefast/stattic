//! A one-time source transformation. No legacy runtime is installed in the target.
mod client;
pub(super) mod codes;
mod endpoints;
mod manifest;
mod omitted;
mod pages;
mod spacefast;
mod transform;

use super::{Entry, Failure, fail};
use crate::loader::Fs;
use crate::{diag, loader, pipeline};
use serde::Serialize;
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};
#[cfg(feature = "cli")]
use std::process::ExitCode;
use std::rc::Rc;

/// What a capsule's 0.8 tables become.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Tables<'a> {
    /// `--space <id>`: bind the published Space's existing `sf_<space>_<table>_<hash>` tables.
    Adopt(&'a str),
    /// `--fresh`: plain v2 tables, created empty by the candidate's first migration.
    Fresh,
    /// Neither: a capsule with a schema is refused (`Z08_SPACE_REQUIRED`).
    Unset,
}

impl<'a> Tables<'a> {
    /// The mode from `--space` and `--fresh` (wasm: `space`, `fresh`). `fresh` wins over a Space
    /// name, so a new Space can pass its id without adopting tables it doesn't have.
    pub fn from(space: Option<&'a str>, fresh: bool) -> Tables<'a> {
        match space.filter(|s| !s.is_empty()) {
            _ if fresh => Tables::Fresh,
            Some(space) => Tables::Adopt(space),
            None => Tables::Unset,
        }
    }
}

pub(super) const SPACE_REQUIRED: &str = "the schema needs a table mode: pass --fresh for a Space with no 0.8 tables, or --space <id> to adopt existing ones";

#[derive(Serialize)]
pub struct Report {
    version: u32,
    source: &'static str,
    status: &'static str,
    entries: Vec<Entry>,
    errors: Vec<Failure>,
    /// `adopt` binds a published Space's 0.8 tables (`--space`); `fresh` creates new v2 tables.
    tables: &'static str,
    /// The candidate's first migration: adopting the 0.8 tables, or creating fresh ones.
    adoption_sql: Option<String>,
    connector_roles: serde_json::Value,
}

#[cfg(feature = "cli")]
const USAGE: &str = "usage: typedpress migrate app --from zero-0.8 [--manifest <finalize.json>] [<capsule-dir>] --out <new-dir> [--space <space-id> | --fresh] [--users] [--zero-css <zero.css>] [--dry-run] [--json]";

#[cfg(feature = "cli")]
struct Args {
    input: Option<PathBuf>,
    manifest: Option<PathBuf>,
    out: PathBuf,
    space: Option<String>,
    fresh: bool,
    dry_run: bool,
    users: bool,
    zero_css: Option<PathBuf>,
}

#[cfg(feature = "cli")]
fn args(raw: &[String]) -> Result<Args, String> {
    let mut from = None;
    let mut input = None;
    let mut manifest = None;
    let mut out = None;
    let mut space = None;
    let mut dry_run = false;
    let mut fresh = false;
    let mut users = false;
    let mut zero_css = None;
    let mut json = false;
    let mut it = raw.iter();
    while let Some(arg) = it.next() {
        match arg.as_str() {
            "--from" if from.is_none() => from = Some(it.next().ok_or(USAGE)?.as_str()),
            "--manifest" if manifest.is_none() => manifest = Some(PathBuf::from(it.next().ok_or(USAGE)?)),
            "--out" if out.is_none() => out = Some(PathBuf::from(it.next().ok_or(USAGE)?)),
            "--space" if space.is_none() => space = Some(it.next().ok_or(USAGE)?.clone()),
            "--users" if !users => users = true,
            "--zero-css" if zero_css.is_none() => zero_css = Some(PathBuf::from(it.next().ok_or(USAGE)?)),
            "--fresh" if !fresh => fresh = true,
            "--dry-run" if !dry_run => dry_run = true,
            "--json" if !json => json = true,
            s if s.starts_with('-') => return Err(format!("unknown or repeated flag {s}\n{USAGE}")),
            _ if input.is_none() => input = Some(PathBuf::from(arg)),
            _ => return Err(USAGE.into()),
        }
    }
    if from != Some("zero-0.8") {
        return Err(USAGE.into());
    }
    if input.is_none() && manifest.is_none() {
        return Err(USAGE.into());
    }
    if fresh && space.is_some() {
        return Err(format!(
            "--space adopts a published Space's 0.8 tables and --fresh creates new ones; pass one\n{USAGE}"
        ));
    }
    Ok(Args {
        input,
        manifest,
        out: out.ok_or(USAGE)?,
        space,
        fresh,
        dry_run,
        users,
        zero_css,
    })
}

#[cfg(feature = "cli")]
pub(crate) fn cli(raw: &[String]) -> ExitCode {
    let args = match args(raw) {
        Ok(args) => args,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(2);
        }
    };
    let zero_css = match args.zero_css.as_deref().map(std::fs::read_to_string).transpose() {
        Ok(css) => css,
        Err(e) => {
            eprintln!("cannot read --zero-css: {e}");
            return ExitCode::from(2);
        }
    };
    let inputs = Inputs {
        users: args.users,
        zero_css,
    };
    let mut candidate = match &args.manifest {
        None => migrate(
            Rc::new(loader::Disk),
            args.input.as_deref().expect("input"),
            Tables::from(args.space.as_deref(), args.fresh),
            inputs,
        ),
        Some(path) => match std::fs::read_to_string(path) {
            Ok(text) => migrate_manifest(
                Rc::new(loader::Disk),
                &text,
                args.input.as_deref(),
                Tables::from(args.space.as_deref(), args.fresh),
                inputs,
            ),
            Err(e) => Candidate::refused(fail("", format!("cannot read finalize manifest: {e}"))),
        },
    };
    if std::fs::symlink_metadata(&args.out).is_ok() {
        candidate.report.errors.push(fail(
            "",
            "output already exists; choose a new directory; no files are overwritten",
        ));
    }
    if candidate.report.errors.is_empty() {
        if args.dry_run {
            candidate.report.status = "dry-run";
        } else if let Err(e) = write_candidate(&args.out, &candidate.files) {
            candidate.report.errors.push(e);
        } else {
            candidate.report.status = "written";
        }
    }
    if !candidate.report.errors.is_empty() {
        candidate.report.status = "refused";
    }
    candidate.finish();
    println!(
        "{}",
        serde_json::to_string_pretty(&candidate.report).expect("report JSON")
    );
    if candidate.report.errors.is_empty() {
        ExitCode::SUCCESS
    } else {
        ExitCode::from(1)
    }
}

/// What the caller knows about the published 0.8 app beyond its source tree.
#[derive(Default)]
pub struct Inputs {
    /// The Space used Space Users: `ctx.auth.userId` maps to each member's copied subject.
    pub users: bool,
    /// The `zero.css` 0.8 compiled for this source (its Tailwind utilities, theme and baseline).
    /// A converted client app links it as 0.8's shell did; without it the app is refused.
    pub zero_css: Option<String>,
}

#[derive(Serialize)]
pub struct Candidate {
    pub report: Report,
    pub files: BTreeMap<String, String>,
}

impl Candidate {
    /// Gives every refusal the diagnostic contract (`codes`); call once, before reporting.
    pub fn finish(&mut self) {
        codes::finish(
            &mut self.report.errors,
            "server/index.ts",
            self.report.tables == "fresh",
        );
    }

    fn refused(error: Failure) -> Candidate {
        let mut candidate = Candidate {
            report: Report::new(),
            files: BTreeMap::new(),
        };
        candidate.report.errors.push(error);
        candidate
    }
}

impl Report {
    fn new() -> Report {
        Report {
            version: 1,
            source: "zero-0.8",
            status: "refused",
            entries: vec![],
            errors: vec![],
            tables: "adopt",
            adoption_sql: None,
            connector_roles: serde_json::json!({}),
        }
    }
}

/// Converts from a stored 0.8 finalize manifest (`{ artifact, endpointSources }`). With `source`,
/// the original files must match the manifest's hashes and then convert as a source run does.
/// Without it, the manifest's own refusals are reported, and the app is refused at `/source`:
/// bundled endpoint JavaScript has no types, so it cannot become authored TypeScript.
pub fn migrate_manifest(
    fs: Rc<dyn Fs>,
    text: &str,
    source: Option<&Path>,
    tables: Tables,
    mut inputs: Inputs,
) -> Candidate {
    let manifest = match manifest::parse(text) {
        Ok(m) => m,
        Err(e) => return Candidate::refused(e),
    };
    let Some(root) = source else {
        let mut candidate = Candidate::refused(fail(
            "/source",
            format!(
                "{}: the finalize manifest keeps bundled JavaScript, not the authored TypeScript; supply the source at the published commit",
                manifest::NOT_RECOVERABLE
            ),
        ));
        let report = &mut candidate.report;
        manifest.check(&mut report.entries, &mut report.errors);
        // The source refusal stays last, so an app's own blockers rank first.
        report.errors.rotate_left(1);
        return candidate;
    };
    let verified = match fs
        .canonicalize(root)
        .map_err(io)
        .and_then(|root| manifest.verify(fs.as_ref(), &root))
    {
        Ok(files) => files,
        Err(e) => {
            let mut candidate = Candidate::refused(e);
            manifest.check(&mut candidate.report.entries, &mut candidate.report.errors);
            return candidate;
        }
    };
    // Convert from the verified files alone: a page, config or import the publish did not record
    // is simply absent, so nothing added after the publish can reach the candidate.
    let published = PathBuf::from("/published");
    let memory: Rc<dyn Fs> = Rc::new(crate::wasm::Memory::new(
        verified
            .iter()
            .map(|(path, text)| (published.join(path), text.clone()))
            .collect(),
    ));
    // The source must also declare the entry the publish compiled.
    if let Some(expected) = manifest.server_entry()
        && let Ok(entry) = server_entry(memory.as_ref(), &published, &mut Report::new())
        && entry != Path::new(expected.trim_start_matches("./"))
    {
        return Candidate::refused(fail(
            "/source",
            format!(
                "source differs from the published version: its server entry is {}, the publish compiled {expected}",
                entry.display()
            ),
        ));
    }
    // The published version's own zero.css, when the payload kept its bytes.
    if inputs.zero_css.is_none() {
        inputs.zero_css = manifest.zero_css();
    }
    let mut candidate = migrate(memory, &published, tables, inputs);
    // The manifest records the capsule's source, not the static files served beside it.
    let recorded = verified.keys().cloned().collect();
    let mut unverified = vec![];
    if let Ok(root) = fs.canonicalize(root) {
        omitted::unverified(fs.as_ref(), &root, &recorded, &mut unverified);
    }
    if !unverified.is_empty() {
        candidate.report.errors.extend(unverified);
        candidate.report.status = "refused";
        candidate.files.clear();
    }
    candidate
}

pub fn migrate(fs: Rc<dyn Fs>, input: &Path, tables: Tables, inputs: Inputs) -> Candidate {
    let mut report = Report {
        version: 1,
        source: "zero-0.8",
        status: "refused",
        entries: vec![],
        errors: vec![],
        tables: match tables {
            Tables::Adopt(_) => "adopt",
            Tables::Fresh => "fresh",
            Tables::Unset => "unset",
        },
        adoption_sql: None,
        connector_roles: serde_json::json!({}),
    };
    let mut files = BTreeMap::new();
    if let Err(e) = convert(fs, input, tables, inputs, &mut report, &mut files) {
        report.errors.push(e);
    }
    if !report.errors.is_empty() {
        files.clear();
    } else {
        report.status = "candidate";
    }
    Candidate { report, files }
}

fn io(e: std::io::Error) -> Failure {
    fail("", e.to_string())
}

fn convert(
    fs: Rc<dyn Fs>,
    input: &Path,
    tables: Tables,
    inputs: Inputs,
    report: &mut Report,
    files: &mut BTreeMap<String, String>,
) -> Result<(), Failure> {
    let root = fs.canonicalize(input).map_err(io)?;
    let entry = server_entry(fs.as_ref(), &root, report)?;
    let mut sources = BTreeMap::new();
    collect_sources(&*fs, &root, &entry, &mut sources, &mut BTreeSet::new())?;
    for dir in ["islands", "layouts", "_pages"] {
        if fs.exists(&root.join(dir)) {
            refuse_pages(&*fs, &root.join(dir), &format!("/{dir}"), &mut report.errors)?;
        }
    }
    let (converted, page_sources) = convert_pages(&*fs, &root, report, &mut sources)?;
    // 0.8 served `client/` as a single-page app when the capsule had no pages.
    let client_app = if converted.is_empty() && !has_pages(&*fs, &root) {
        client_entry(&*fs, &root)?
    } else {
        None
    };
    let transformed = transform::app(
        &entry,
        sources
            .get(&entry)
            .ok_or_else(|| fail("", "entry source must resolve to server/index.ts inside the capsule"))?,
        tables,
        inputs.users,
        fs.is_dir(&root.join("content/posts")),
        &mut report.entries,
        &mut report.errors,
    )?;
    report.connector_roles = transformed.connector_roles;
    let declares_posts = transformed.declares_posts;
    let client_files = match &client_app {
        Some(file) => {
            if fs.exists(&root.join("public")) {
                report.errors.push(
                    fail(
                        "/public",
                        format!("{}: the converted client app goes to public/", client::TAG_PUBLIC),
                    )
                    .located("public".into(), 1, 1),
                );
            }
            client::convert(
                &*fs,
                &root,
                file,
                client::App {
                    title: &transformed.title,
                    zero_css: inputs.zero_css.as_deref(),
                    handlers: &transformed.handlers,
                },
                &mut report.entries,
                &mut report.errors,
            )
            .files
        }
        None => BTreeMap::new(),
    };
    if sources.contains_key(Path::new("typedpress.config.ts"))
        || sources.contains_key(Path::new("server/__z08-content.ts"))
        || sources.keys().any(|p| p.starts_with(crate::layout::ROOT))
    {
        return Err(fail("", "input imports a reserved generated config path"));
    }
    let canonical = PathBuf::from("server/index.ts");
    if entry != canonical {
        if sources.contains_key(&canonical) {
            return Err(fail(
                "",
                "server/index.ts exists beside the configured server entry; rename one",
            ));
        }
        sources.remove(&entry);
    }
    sources.insert(canonical, transformed.config);
    for (path, text) in transformed.routes {
        if sources.contains_key(&path) {
            return Err(fail(
                "",
                format!(
                    "{} exists beside a converted endpoint's route file; rename one",
                    path.display()
                ),
            ));
        }
        sources.insert(path, text);
    }
    if let Some(content) = transformed.content_config {
        sources.insert(PathBuf::from("server/__z08-content.ts"), content);
    }
    // The candidate's app code lives under the TypedPress root (docs/spec/spec.md §1), and what 0.8 kept
    // under `pages/` (the pages and the data modules beside them) under `tp/app/`. Both move
    // down by the same one folder, so every relative import between them still resolves.
    let mut sources: BTreeMap<PathBuf, String> = sources
        .into_iter()
        .map(|(path, text)| match path.strip_prefix("pages") {
            Ok(rest) => (Path::new(pages::APP).join(rest), text),
            Err(_) => (Path::new(crate::layout::ROOT).join(path), text),
        })
        .collect();
    sources.insert(
        PathBuf::from("typedpress.config.ts"),
        "export { default } from \"./tp/server/index\";\n".into(),
    );
    let page_files: BTreeMap<String, String> = converted;
    // Fail closed: whatever 0.8 served that the candidate does not reproduce is an error, so a
    // candidate without errors can replace the published version. Static files v2 serves
    // unchanged travel in public/.
    let config = CONFIG_FILES
        .iter()
        .find(|f| fs.is_file(&root.join(f)))
        .and_then(|name| {
            let text = fs.read_to_string(&root.join(name)).ok()?;
            let value = serde_json::from_str::<serde_json::Value>(&strip_jsonc(&text)).ok()?;
            Some((*name, value, text))
        });
    // A page or client file already refused with its own reason isn't reported twice.
    let reported: BTreeSet<String> = report.errors.iter().map(|e| e.pointer_file()).collect();
    let mut handled: Vec<String> = page_sources.clone();
    handled.extend(reported.iter().cloned());
    // The 0.8 client entry wherever `runtime.client` puts it, pages or not.
    let resolved_client = client_entry(&*fs, &root)?;
    let mut static_files = omitted::sweep(
        &*fs,
        &root,
        config.as_ref().map(|(name, value, text)| (*name, value, text.as_str())),
        &handled,
        resolved_client.as_deref(),
        // The client is reproduced as the pages layout, or converted to public/ by `client`.
        page_files.contains_key(&format!("{}/layout.tsx", pages::APP)) || client_app.is_some(),
        client_app.is_some(),
        declares_posts,
        &client_files.keys().cloned().collect::<Vec<_>>(),
        &transformed.get_paths,
        &mut report.errors,
    );
    // A static file never replaces a generated client file; that would be a silent drop.
    for path in client_files.keys() {
        if static_files.files.remove(path).is_some() {
            let name = path.trim_start_matches("public/").to_string();
            static_files.carried.retain(|c| *c != name);
            report.errors.push(
                fail(
                    &format!("/{name}"),
                    format!("{}: the converted client app writes public/{name}", omitted::STATIC),
                )
                .located(name, 1, 1),
            );
        }
    }
    // The platform keys travel in the candidate's own sf.jsonc, unchanged and in their order;
    // every other key was refused above, or changes nothing the candidate serves.
    let mut kept = std::mem::take(&mut static_files.kept);
    if let Some((name, _, _)) = &config {
        for key in kept.keys() {
            report.entries.push(super::entry(
                &format!("/{name}/{key}"),
                "mapped",
                format!("Spacefast applies `{key}` to the Space; it travels unchanged in the candidate's sf.jsonc"),
            ));
        }
    }
    // The capsule's connector roles join them, unless the config already declares its own roles,
    // which 0.8 served instead of the capsule's.
    let roles = report.connector_roles.as_object().cloned().unwrap_or_default();
    if kept.contains_key("connectors") {
        for role in roles.keys() {
            report.entries.push(super::entry(
                &super::pointer("/connectors", role),
                "mapped",
                "the Space config declares connector roles, which Spacefast served instead of the capsule's; they travel in the candidate's sf.jsonc",
            ));
        }
    } else if let Some(connectors) = connector_config(
        &roles,
        &transformed.connector_spans,
        &mut report.entries,
        &mut report.errors,
    ) {
        kept.insert("connectors".into(), connectors);
    }
    if !kept.is_empty() {
        let text = serde_json::to_string_pretty(&serde_json::Value::Object(kept)).expect("config json");
        static_files.config = Some(format!("{text}\n"));
    }
    for name in &static_files.carried {
        report.entries.push(super::entry(
            &format!("/{name}"),
            "mapped",
            format!("static file carried over as public/{name}, served at the same URL"),
        ));
    }
    // Both dry runs and writes compile the same candidate, before creating a destination.
    if !report.errors.is_empty() {
        return Ok(());
    }
    let checked_root = PathBuf::from("/candidate");
    let memory = crate::wasm::Memory::new(
        sources
            .iter()
            .map(|(path, text)| (checked_root.join(path), text.clone()))
            .collect(),
    );
    let program = loader::load_from(Rc::new(memory), &checked_root.join("typedpress.config.ts"))
        .map_err(|d| fail("", diag::render(&d[0], &checked_root)).spanned(d[0].span, &checked_root))?;
    let built = pipeline::build(&checked_root, &program, || Ok(pipeline::Previous::none()), |_| None).map_err(|d| {
        fail(
            "",
            format!(
                "generated application refused by v2 compiler: {}",
                diag::render(&d, &checked_root)
            ),
        )
        .spanned(d.span, &checked_root)
    })?;
    let sql = built
        .migrations
        .iter()
        .map(|(_, sql)| sql.as_str())
        .collect::<Vec<_>>()
        .join("\n");
    report.adoption_sql = Some(sql.clone());
    for (path, text) in sources {
        files.insert(path.to_string_lossy().replace('\\', "/"), text);
    }
    for (path, text) in page_files {
        files.insert(path, text);
    }
    for (path, text) in client_files {
        files.insert(path, text);
    }
    for (path, text) in static_files.files {
        files.insert(path, text);
    }
    if let Some(text) = static_files.config {
        files.insert("sf.jsonc".into(), text);
    }
    // A Space binding adopts its tables; without one the candidate creates them.
    let first = if matches!(tables, Tables::Adopt(_)) {
        "0001_adopt.sql"
    } else {
        "0001_init.sql"
    };
    files.insert(format!("{}/{first}", crate::pipeline::LEDGER), sql);
    files.insert(
        format!("{}/snapshot.json", crate::pipeline::LEDGER),
        serde_json::to_string_pretty(&built.snapshot).unwrap(),
    );
    files.insert(
        "connector-roles.json".into(),
        serde_json::to_string_pretty(&report.connector_roles).unwrap(),
    );
    Ok(())
}

/// The Space config's `connectors` for a capsule's roles: each exactly as Spacefast froze it for the
/// 0.8 version (the monorepo's `capsuleConnectorRoles`): its connector, `as` with `owner` named
/// `personal`, every tool, no pinned arguments, the default cache. Role names don't change, so the
/// Space's bindings and visitor connections, keyed by role, keep serving them. A role the config
/// can't hold is refused at its declaration. None without roles.
fn connector_config(
    roles: &serde_json::Map<String, serde_json::Value>,
    spans: &BTreeMap<String, diag::Span>,
    entries: &mut Vec<Entry>,
    errors: &mut Vec<Failure>,
) -> Option<serde_json::Value> {
    /// `CONNECTOR_ROLES_MAX` and `CONNECTOR_ROLE_CACHE_DEFAULT` in the monorepo's connector-roles.ts.
    const ROLES_MAX: usize = 32;
    const CACHE_DEFAULT: &str = "30s";
    let refuse = |role: &str, why: String| {
        let failure = fail(
            &super::pointer("/connectors", role),
            format!("{}: {why}", omitted::CONNECTORS),
        );
        match spans.get(role) {
            Some(span) => failure.spanned(*span, Path::new("")),
            None => failure,
        }
    };
    if roles.is_empty() {
        return None;
    }
    if roles.len() > ROLES_MAX {
        let first = roles.keys().next().expect("a role");
        errors.push(refuse(
            first,
            format!(
                "a Space config declares at most {ROLES_MAX} connector roles, and the capsule declares {}",
                roles.len()
            ),
        ));
        return None;
    }
    let mut out = serde_json::Map::new();
    for (role, declared) in roles {
        // `connectorRoleNameSchema` and the slug rule of `connectorRoleEntrySchema`.
        let named = role.len() <= 64
            && role.starts_with(|c: char| c.is_ascii_lowercase())
            && role.chars().all(|c| c.is_ascii_alphanumeric());
        if !named {
            errors.push(refuse(
                role,
                format!(
                    "`{role}` isn't a config role name (camelCase, starting with a lowercase letter); rename the role"
                ),
            ));
            continue;
        }
        let Some(connector) = declared.get("connector").and_then(serde_json::Value::as_str) else {
            errors.push(refuse(
                role,
                "a config role names its connector; add `connector: \"<slug>\"` (the connector this Space's binding for the role uses, such as \"github\")".into(),
            ));
            continue;
        };
        let slug = connector.len() <= 128
            && connector.starts_with(|c: char| c.is_ascii_lowercase() || c.is_ascii_digit())
            && connector
                .chars()
                .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '-');
        if !slug {
            errors.push(refuse(
                role,
                format!("`{connector}` isn't a connector slug (lowercase letters, digits and dashes)"),
            ));
            continue;
        }
        let kind = declared.get("as").and_then(serde_json::Value::as_str).unwrap_or("team");
        out.insert(
            role.clone(),
            serde_json::json!({ "connector": connector, "as": kind, "tools": "*", "cache": CACHE_DEFAULT }),
        );
        entries.push(super::entry(
            &super::pointer("/connectors", role),
            "mapped",
            format!("carried into sf.jsonc `connectors` as a `{kind}` role with every tool and the default cache, as Spacefast served it; the Space's binding for `{role}` keeps serving it. Pages call it with @spacefast/client; server code can't call connectors on TypedPress yet"),
        ));
    }
    (out.len() == roles.len()).then_some(serde_json::Value::Object(out))
}

#[cfg(feature = "cli")]
fn write_candidate(out: &Path, files: &BTreeMap<String, String>) -> Result<(), Failure> {
    use std::io::Write;
    let parent = out
        .parent()
        .filter(|p| !p.as_os_str().is_empty())
        .unwrap_or(Path::new("."));
    std::fs::create_dir_all(parent).map_err(io)?;
    std::fs::create_dir(out).map_err(io)?;
    let result = (|| {
        for (path, source) in files {
            let target = out.join(path);
            std::fs::create_dir_all(target.parent().expect("file parent")).map_err(io)?;
            let mut file = std::fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .open(target)
                .map_err(io)?;
            file.write_all(source.as_bytes()).map_err(io)?;
        }
        Ok(())
    })();
    if result.is_err() {
        let _ = std::fs::remove_dir_all(out);
    }
    result
}

fn collect_sources(
    fs: &dyn Fs,
    root: &Path,
    path: &Path,
    out: &mut BTreeMap<PathBuf, String>,
    seen: &mut BTreeSet<PathBuf>,
) -> Result<(), Failure> {
    let absolute = fs.canonicalize(&root.join(path)).map_err(|e| {
        fail(
            "",
            format!(
                "cannot read capsule source {}: {e}; compiled finalize payloads are not capsule source",
                path.display()
            ),
        )
    })?;
    if !absolute.starts_with(root) {
        return Err(fail("", "source import escapes the capsule directory"));
    }
    let relative = absolute.strip_prefix(root).expect("inside root").to_path_buf();
    if !seen.insert(relative.clone()) {
        return Ok(());
    }
    let source = fs.read_to_string(&absolute).map_err(io)?;
    let body = transform::parse(&relative, &source)?;
    for import in transform::imports(&body) {
        if import.starts_with('.') {
            let base = relative.parent().expect("source parent").join(import);
            let target = if fs.is_file(&root.join(&base)) {
                base
            } else {
                base.with_extension("ts")
            };
            collect_sources(fs, root, &target, out, seen)?;
        } else if import != "@spacefast/zero/server" {
            return Err(fail(
                "",
                format!("package {import:?} needs a manual port; only local TypeScript helpers are copied"),
            ));
        }
    }
    out.insert(relative, source);
    Ok(())
}

/// 0.8 config files in the finalizer's precedence order (`SPACE_CONFIG_PRECEDENCE`).
const CONFIG_FILES: &[&str] = &[
    "sf.jsonc",
    "spacefast.jsonc",
    "spacefast.json",
    "sf.json",
    ".sf/sf.json",
    ".sf/config.jsonc",
    ".sf/config.json",
];

/// The capsule's server module: `runtime.server` of the selected 0.8 config, else
/// `server/index.ts`. It is renamed to `server/index.ts` in the candidate.
fn server_entry(fs: &dyn Fs, root: &Path, report: &mut Report) -> Result<PathBuf, Failure> {
    if fs.is_file(&root.join("spacefast.config.ts")) {
        return Err(fail(
            "/spacefast.config.ts",
            "an executable Spacefast config needs a manual port; write its runtime as sf.jsonc",
        ));
    }
    let Some(name) = CONFIG_FILES.iter().find(|f| fs.is_file(&root.join(f))) else {
        return Ok(PathBuf::from("server/index.ts"));
    };
    let text = fs.read_to_string(&root.join(name)).map_err(io)?;
    let config: serde_json::Value = serde_json::from_str(&strip_jsonc(&text))
        .map_err(|e| fail(&format!("/{name}"), format!("cannot read {name}: {e}")))?;
    let Some(server) = config.pointer("/runtime/server").and_then(serde_json::Value::as_str) else {
        return Ok(PathBuf::from("server/index.ts"));
    };
    let server = server.trim_start_matches("./");
    if server != "server/index.ts" {
        report.entries.push(super::entry(
            &format!("/{name}/runtime/server"),
            "mapped",
            format!("server entry {server} becomes server/index.ts"),
        ));
    }
    // Renaming keeps relative imports valid only for a module directly under server/.
    let direct = server.strip_prefix("server/").is_some_and(|rest| !rest.contains('/'));
    if !direct || !(server.ends_with(".ts") || server.ends_with(".tsx")) {
        return Err(fail(
            &format!("/{name}/runtime/server"),
            "the server entry must be a module directly under server/ (server/<name>.ts)",
        ));
    }
    Ok(PathBuf::from(server))
}

/// The 0.8 config's `runtime.client`, else `client/index.tsx`, when that file exists.
fn client_entry(fs: &dyn Fs, root: &Path) -> Result<Option<PathBuf>, Failure> {
    let mut client = "client/index.tsx".to_string();
    if let Some(name) = CONFIG_FILES.iter().find(|f| fs.is_file(&root.join(f))) {
        let text = fs.read_to_string(&root.join(name)).map_err(io)?;
        let config: serde_json::Value = serde_json::from_str(&strip_jsonc(&text))
            .map_err(|e| fail(&format!("/{name}"), format!("cannot read {name}: {e}")))?;
        if let Some(c) = config.pointer("/runtime/client").and_then(serde_json::Value::as_str) {
            client = c.trim_start_matches("./").to_string();
        }
    }
    let path = PathBuf::from(&client);
    let inside = path.components().all(|c| matches!(c, std::path::Component::Normal(_)));
    Ok((inside && fs.is_file(&root.join(&path)) && !fs.is_symlink(&root.join(&path))).then_some(path))
}

/// Whether `pages/` holds anything 0.8 routed as a page.
fn has_pages(fs: &dyn Fs, root: &Path) -> bool {
    let mut stack = vec![root.join("pages")];
    while let Some(dir) = stack.pop() {
        for path in fs.read_dir(&dir).unwrap_or_default() {
            if fs.is_dir(&path) {
                stack.push(path);
            } else if path.extension().is_some_and(|e| e == "tsx" || e == "md" || e == "html") {
                return true;
            }
        }
    }
    false
}

/// JSONC → JSON: comments and trailing commas outside strings.
fn strip_jsonc(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut chars = text.chars().peekable();
    let mut string = false;
    while let Some(c) = chars.next() {
        if string {
            out.push(c);
            if c == '\\' {
                out.extend(chars.next());
            } else if c == '"' {
                string = false;
            }
            continue;
        }
        match (c, chars.peek()) {
            ('"', _) => {
                string = true;
                out.push(c);
            }
            ('/', Some('/')) => {
                for c in chars.by_ref() {
                    if c == '\n' {
                        out.push('\n');
                        break;
                    }
                }
            }
            ('/', Some('*')) => {
                chars.next();
                let mut last = ' ';
                for c in chars.by_ref() {
                    if last == '*' && c == '/' {
                        break;
                    }
                    last = c;
                }
            }
            _ => out.push(c),
        }
    }
    // Trailing commas: a comma followed only by whitespace before } or ].
    let re = regex::Regex::new(r",(\s*[}\]])").expect("regex");
    re.replace_all(&out, "$1").into_owned()
}

/// Static 0.8 pages and the client `Layout` become v2 TSX pages. Interactive ones are refused.
fn convert_pages(
    fs: &dyn Fs,
    root: &Path,
    report: &mut Report,
    sources: &mut BTreeMap<PathBuf, String>,
) -> Result<(BTreeMap<String, String>, Vec<String>), Failure> {
    let mut out = BTreeMap::new();
    // The 0.8 page files that became v2 pages, capsule-relative.
    let mut converted = vec![];
    let pages_dir = root.join("pages");
    if !fs.exists(&pages_dir) {
        return Ok((out, converted));
    }
    let mut stack = vec![pages_dir];
    let mut files = vec![];
    while let Some(dir) = stack.pop() {
        if !fs.is_dir(&dir) || fs.is_symlink(&dir) {
            report.errors.push(fail(
                &pages::at(dir.strip_prefix(root).unwrap_or(&dir)),
                "pages must be a directory of files",
            ));
            continue;
        }
        let mut entries = fs.read_dir(&dir).map_err(io)?;
        entries.sort();
        for path in entries {
            if fs.is_dir(&path) && !fs.is_symlink(&path) {
                stack.push(path);
            } else {
                files.push(path);
            }
        }
    }
    files.sort();
    for path in files {
        let relative = path.strip_prefix(root).expect("inside root").to_path_buf();
        let at = pages::at(&relative);
        if fs.is_symlink(&path) {
            report.errors.push(fail(&at, "symlinked pages need a manual port"));
            continue;
        }
        // 0.8 routed only .tsx, .md and .html under pages/; other files are modules pages import.
        if !relative
            .extension()
            .is_some_and(|e| e == "tsx" || e == "md" || e == "html")
        {
            continue;
        }
        let text = fs.read_to_string(&path).map_err(io)?;
        if relative.extension().is_some_and(|e| e == "md" || e == "html") {
            report.errors.push(fail(&at, "content-owned 0.8 pages have no v2 counterpart yet (docs/pages.md, Not in this release); move the text into a TSX page or a collection"));
            continue;
        }
        match pages::page(&relative, &text, &at) {
            Ok(page) => {
                // Data modules a page imports are copied like the server's helpers. A component
                // module (.tsx) has no data-module counterpart: it is the page's to port.
                let mut component = None;
                for import in &page.imports {
                    // Root-relative, with `.ts` appended (`shared/Widget.ts`, `shared/Widget.tsx.ts`).
                    let stem = import.strip_suffix(".ts").unwrap_or(import);
                    let explicit = stem.ends_with(".tsx");
                    let only_tsx = !fs.is_file(&root.join(import)) && fs.is_file(&root.join(format!("{stem}.tsx")));
                    if explicit || only_tsx {
                        component = Some(if explicit {
                            stem.to_string()
                        } else {
                            format!("{stem}.tsx")
                        });
                        break;
                    }
                }
                if let Some(import) = component {
                    report.errors.push(fail(
                        &at,
                        format!("the page imports the component module {import}; port it with the page to a TSX page component"),
                    ));
                    continue;
                }
                for import in &page.imports {
                    collect_sources(fs, root, Path::new(import), sources, &mut BTreeSet::new())?;
                }
                report.entries.push(super::entry(
                    &at,
                    "mapped",
                    format!(
                        "static page becomes {}, rendered by WordPress on each request",
                        page.file
                    ),
                ));
                converted.push(relative.to_string_lossy().replace('\\', "/"));
                if out.insert(page.file.clone(), page.source).is_some() {
                    report
                        .errors
                        .push(fail(&at, format!("two 0.8 pages map to {}", page.file)));
                }
            }
            Err(e) => report.errors.push(e),
        }
    }
    let client = root.join("client/index.tsx");
    if !out.is_empty() && fs.is_file(&client) {
        let text = fs.read_to_string(&client).map_err(io)?;
        match pages::layout(&text, "/client/index.tsx") {
            Ok(Some(layout)) => {
                report.entries.push(super::entry(
                    "/client/index.tsx",
                    "mapped",
                    "Layout becomes tp/app/layout.tsx",
                ));
                out.insert(format!("{}/layout.tsx", pages::APP), layout);
            }
            Ok(None) => {}
            Err(e) => report.errors.push(e),
        }
    }
    Ok((out, converted))
}

fn refuse_pages(fs: &dyn Fs, path: &Path, at: &str, errors: &mut Vec<Failure>) -> Result<(), Failure> {
    if !fs.is_dir(path) || fs.is_symlink(path) {
        errors.push(fail(at, "page, layout and island compilation needs an explicit port"));
        return Ok(());
    }
    let mut entries = fs.read_dir(path).map_err(io)?;
    entries.sort();
    for path in entries {
        let at = super::pointer(at, &path.file_name().unwrap_or_default().to_string_lossy());
        if fs.is_dir(&path) && !fs.is_symlink(&path) {
            refuse_pages(fs, &path, &at, errors)?;
        } else {
            let text = fs.read_to_string(&path).unwrap_or_default();
            errors.push(fail(&at, pages::reason(&text, "a 0.8 page, layout or island file")));
        }
    }
    Ok(())
}
