use crate::app_plugin::plugin_vars;
use crate::diag::{Diag, Span};
use crate::emit::{app_files, emit_runtime, runtime_file, runtime_tree, write};
use crate::pipeline::{self, Built};
use crate::{diag, layout, loader, platform, wp_plugins};
use std::path::{Path, PathBuf};
use std::process::ExitCode;

/// Compiles the project and writes nothing: the schema, for a command that only needs to know it.
pub(super) fn compile(project: &Path) -> Result<crate::schema::Schema, Diag> {
    build(project, false).map(|built| built.schema)
}

/// Runs the whole pipeline in memory.
pub(super) fn build(project: &Path, frozen: bool) -> Result<Built, Diag> {
    let entry = project.join("typedpress.config.ts");
    if !entry.is_file() {
        return Err(Diag::new(
            Span::NONE,
            format!("no typedpress.config.ts in {}", project.display()),
        ));
    }
    let program = loader::load(&entry)?;
    build_program(project, &program, frozen)
}

/// The in-memory pipeline over this project's last snapshot, migration numbering and plugin lock.
fn build_program(project: &Path, program: &loader::Program, frozen: bool) -> Result<Built, Diag> {
    pipeline::build(
        project,
        program,
        || {
            let mut previous = read_previous(project)?;
            previous.frozen = frozen;
            Ok(previous)
        },
        runtime_file,
    )
}

fn migrations_dir(project: &Path) -> PathBuf {
    project.join(pipeline::LEDGER)
}

/// The last build, from the ledger: the snapshot and everything beside it.
fn read_previous(project: &Path) -> Result<pipeline::Previous, Diag> {
    if project.join(pipeline::OLD_LEDGER).exists() {
        return Err(pipeline::moved_ledger());
    }
    let dir = migrations_dir(project);
    let ledger = pipeline::LEDGER;
    // Only a missing file is a first build. A snapshot that is there but cannot be read (permissions,
    // a directory in its place, not UTF-8) is an error: diffing against an empty schema would plan a
    // migration that recreates every table.
    let snapshot = match std::fs::read_to_string(dir.join("snapshot.json")) {
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
        Err(e) => {
            return Err(Diag::new(Span::NONE, format!("can't read {ledger}/snapshot.json: {e}")));
        }
        Ok(text) => Some(
            serde_json::from_str(&text)
                .map_err(|e| Diag::new(Span::NONE, format!("{ledger}/snapshot.json is not valid JSON: {e}")))?,
        ),
    };
    let names = migration_names(&dir)?;
    pipeline::Previous::from_project(snapshot, names.iter().map(String::as_str))
}

/// Every name in the ledger directory. The next migration's number comes from these, so an entry that
/// cannot be listed or named is an error: skipping it could reuse a number that is already taken.
fn migration_names(dir: &Path) -> Result<Vec<String>, Diag> {
    let entries = match std::fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(Vec::new()),
        Err(e) => return Err(Diag::new(Span::NONE, format!("can't read {}/: {e}", pipeline::LEDGER))),
    };
    let mut names = Vec::new();
    for entry in entries {
        let entry = entry.map_err(|e| Diag::new(Span::NONE, format!("can't read {}/: {e}", pipeline::LEDGER)))?;
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            return Err(Diag::new(
                Span::NONE,
                format!(
                    "{}/{} is not a UTF-8 file name; rename or remove it",
                    pipeline::LEDGER,
                    name.to_string_lossy()
                ),
            ));
        };
        names.push(name.to_string());
    }
    Ok(names)
}

/// Writes a file that must not exist yet. A migration is numbered from the files beside it, so one
/// that would land on an existing name is a numbering error and fails here rather than replacing the
/// file.
fn write_new(path: &Path, content: impl AsRef<[u8]>) -> std::io::Result<()> {
    use std::io::Write as _;
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir)?;
    }
    let mut file = std::fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(path)
        .map_err(|e| std::io::Error::new(e.kind(), format!("{}: {e}", path.display())))?;
    file.write_all(content.as_ref())
}

/// Compiles the project and writes `tp/_generated/` only: what `typedpress pull` runs after it
/// changes the runtime schema lock, so the types and the typed client follow the lock. The error
/// is the rendered diagnostic.
pub(super) fn refresh(project: &Path) -> Result<(), String> {
    let built = build(project, false).map_err(|d| diag::render(&d, project))?;
    emit_project(project, &built).map_err(|e| e.to_string())
}

/// Writes `tp/_generated/` (committed): migrations, the snapshot, `db.d.ts`, the locks, the typed
/// client and `openapi.json`.
pub(super) fn emit_project(project: &Path, built: &Built) -> std::io::Result<()> {
    let migrations = migrations_dir(project);
    for (name, sql) in &built.migrations {
        write_new(&migrations.join(name), sql)?;
    }
    if !built.migrations.is_empty() || !migrations.join("snapshot.json").is_file() {
        write(
            &migrations.join("snapshot.json"),
            serde_json::to_string_pretty(&built.snapshot)? + "\n",
        )?;
    }
    write(&project.join(layout::DB_TYPES), &built.gen_ts)?;
    match &built.routes_ts {
        Some(routes) => {
            write(&project.join(layout::ROUTES_TYPES), routes)?;
        }
        None => {
            let _ = std::fs::remove_file(project.join(layout::ROUTES_TYPES));
        }
    }
    if let Some(lock) = &built.plugin_lock {
        write(
            &project.join(crate::plugins::LOCK_PATH),
            serde_json::to_string_pretty(lock)? + "\n",
        )?;
    }
    write(&project.join(layout::OPENAPI), crate::openapi::render(&built.openapi))?;
    write(&project.join(crate::theme::TOKENS), &built.theme_css)?;
    let client_dir = project.join(layout::CLIENT);
    for (name, content) in &built.client_files {
        write(&client_dir.join(name), content)?;
    }
    Ok(())
}

fn zip_plugin(directory: &Path) -> std::io::Result<()> {
    use std::io::Write;
    let root = directory.parent().unwrap();
    let name = directory.file_name().unwrap().to_string_lossy();
    let mut files = Vec::new();
    listing(directory, directory, &mut files)?;
    // Each directory's entries by name, a directory's contents where its name sorts: `a/b` before
    // `a.txt`, as the archive has always listed them.
    files.sort_by(|a, b| a.0.split('/').cmp(b.0.split('/')));
    // A new file, never one truncated in place: the stage's old ZIP may be a link to the published one.
    let archive = directory.with_extension("zip");
    if std::fs::symlink_metadata(&archive).is_ok() {
        std::fs::remove_file(&archive)?;
    }
    let mut writer = zip::ZipWriter::new(std::fs::File::create(archive)?);
    for (rel, _) in files {
        writer
            .start_file(
                format!("{name}/{rel}"),
                zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Deflated),
            )
            .map_err(std::io::Error::other)?;
        writer.write_all(&std::fs::read(root.join(&*name).join(&rel))?)?;
    }
    writer.finish().map_err(std::io::Error::other)?;
    Ok(())
}

/// Stage the complete artifact, publish it, then persist the sole dependency lock.
pub(super) fn emit_wp_content(project: &Path, root: &Path, built: &Built) -> std::io::Result<PathBuf> {
    let empty = wp_plugins::Config { plugins: Vec::new() };
    let config = built.plugins.as_ref().unwrap_or(&empty);
    let plan = wp_plugins::resolve(config, &platform::plugins(built.schema.locales.is_some()))?;
    let parent = root
        .parent()
        .ok_or_else(|| std::io::Error::other("output needs a parent directory"))?;
    let parent = if parent.as_os_str().is_empty() {
        Path::new(".")
    } else {
        parent
    };
    std::fs::create_dir_all(parent)?;
    let parent = parent.canonicalize()?;
    let root = parent.join(
        root.file_name()
            .ok_or_else(|| std::io::Error::other("output needs a directory name"))?,
    );
    if project.starts_with(&root) || project.join("tp").starts_with(&root) {
        return Err(std::io::Error::other(
            "wp-content output cannot contain the project or its dependency lock",
        ));
    }
    struct Guard(PathBuf, std::fs::File);
    impl Drop for Guard {
        fn drop(&mut self) {
            let _ = self.1.sync_all();
            let _ = std::fs::remove_file(&self.0);
        }
    }
    fn guard(path: PathBuf) -> std::io::Result<Guard> {
        let file = std::fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .open(&path)
            .map_err(|e| std::io::Error::other(format!("cannot acquire build lock {}: {e}", path.display())))?;
        Ok(Guard(path, file))
    }
    let lock = project.join(layout::WP_PLUGINS_LOCK);
    std::fs::create_dir_all(lock.parent().unwrap())?;
    // Serialize the project lockfile and publication to a shared output root. The guard lives in
    // the TypedPress root, which no output may contain (checked above), so publication never renames it
    // away while a build holds it.
    let _project_guard = guard(project.join(layout::ROOT).join(".wp-plugins.build.lock"))?;
    let _output_guard = guard(parent.join(format!(
        ".{}.typedpress-build.lock",
        root.file_name().unwrap().to_string_lossy()
    )))?;
    let mut prepared = wp_plugins::Prepared::acquire(&plan, &lock, &platform::cache(project))?;
    let temp = tempfile::tempdir_in(&parent)?;
    let stage = temp.path().join("wp-content");
    // The stage starts as the published output, its files hard links to the published ones (copies
    // where links aren't possible). Every write below replaces a file rather than writing into it,
    // so the published output is unchanged until the stage takes its place.
    if std::fs::symlink_metadata(&root).is_ok() {
        if !std::fs::symlink_metadata(&root)?.is_dir() {
            return Err(std::io::Error::other("output root must be a real directory"));
        }
        wp_plugins::copy_output(&root, &stage)?;
    } else {
        std::fs::create_dir(&stage)?;
    }
    prepared.install_into(&stage.join("plugins"))?;
    let plugins = stage.join("plugins");
    let generated = crate::app_plugin::tp_plugins(&built.schema);
    let revision = crate::store::runtime_revision();
    let previous_revision = std::fs::read(stage.join("typedpress-manifest.json"))
        .ok()
        .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok())
        .and_then(|manifest| manifest["runtimeRevision"].as_str().map(str::to_string));
    // Admin is also owned here when its opt-in is removed.
    if !built.schema.admin {
        remove_plugin(&plugins.join("typedpress-admin"))?;
    }
    let (schema_php, app) = app_files(project, &migrations_dir(project), built)?;
    let mut versions = serde_json::Map::new();
    for slug in generated {
        let directory = plugins.join(&slug);
        if slug.starts_with("typedpress-app-") {
            let changed = write_app_plugin(&plugins, &directory, &app)?;
            // The app plugin is generated from the project: its changed PHP files are linted. TypedPress's
            // own plugins are the same bytes for every app (embedded from runtime/);
            // tests/php/runtime_lint.php lints those once, so a build doesn't run `php -l` on
            // hundreds of unchanged files.
            php_lint(&changed).map_err(std::io::Error::other)?;
            if !changed.is_empty() || !directory.with_extension("zip").is_file() {
                zip_plugin(&directory)?;
            }
            let hash = plugin_vars(&built.schema, &schema_php)
                .into_iter()
                .find(|(key, _)| *key == "schemaHash")
                .unwrap()
                .1;
            versions.insert(slug, format!("{}+{hash}", env!("CARGO_PKG_VERSION")).into());
        } else {
            // A runtime plugin is the same for every build of this compiler: the previous one is kept
            // when it was written by a compiler with the same runtime revision and still holds the
            // files it wrote (docs/runtime-contract.md, "Build output reuse").
            if previous_revision.as_deref() != Some(revision.as_str()) || !runtime_plugin_intact(&directory, &slug)? {
                remove_plugin(&directory)?;
                emit_runtime(&plugins, &[&slug])?;
                zip_plugin(&directory)?;
            }
            versions.insert(slug, env!("CARGO_PKG_VERSION").into());
        }
    }
    for dependency in prepared.dependencies() {
        versions.insert(dependency.slug.clone(), dependency.version.clone().into());
    }
    let requires: serde_json::Map<String, serde_json::Value> = platform::plugins(built.schema.locales.is_some())
        .into_iter()
        .map(|plugin| (plugin.slug, format!(">={}", plugin.version).into()))
        .collect();
    let mut manifest = serde_json::json!({"typedpress":env!("CARGO_PKG_VERSION"),"runtime":platform::SERIES,"runtimeRevision":revision,"app":built.schema.name,"plugins":versions,"requires":requires,"dependencies":prepared.dependencies().collect::<Vec<_>>(),"locales":built.schema.locales});
    // Sealed mode (docs/sealed.md): the plugin slugs this site runs, for a host to enforce. No key
    // when the app isn't sealed.
    if let Some(sealed) = crate::bundle::sealed_plugins(&built.schema, crate::app_plugin::tp_plugins(&built.schema)) {
        manifest["sealed"] = sealed.into();
    }
    write(
        &stage.join("typedpress-manifest.json"),
        serde_json::to_string_pretty(&manifest)? + "\n",
    )?;

    super::deploy::write_metadata(&stage, built)?;
    let lock_bytes = prepared.lock_bytes()?;
    std::fs::create_dir_all(lock.parent().unwrap())?;
    let mut lock_file = tempfile::NamedTempFile::new_in(lock.parent().unwrap())?;
    std::io::Write::write_all(&mut lock_file, &lock_bytes)?;
    let backup = temp.path().join("previous");
    let existed = root.exists();
    if existed {
        std::fs::rename(&root, &backup)?;
    }
    if let Err(e) = std::fs::rename(&stage, &root) {
        if existed {
            std::fs::rename(&backup, &root)?;
        }
        return Err(e);
    }
    if let Err(e) = lock_file.persist(&lock) {
        std::fs::remove_dir_all(&root)?;
        if existed {
            std::fs::rename(&backup, &root)?;
        }
        return Err(e.error);
    }
    let legacy = project.join(".tp/wp");
    if !root.starts_with(&legacy) && legacy.is_dir() {
        std::fs::remove_dir_all(legacy)?;
    }
    Ok(root)
}

/// `php -l` on each PHP file, a few processes at a time. PHP before 8.3 lints only the first file
/// it is given, so each file gets its own process.
fn php_lint(files: &[PathBuf]) -> Result<(), String> {
    let php: Vec<&PathBuf> = files
        .iter()
        .filter(|path| path.extension().is_some_and(|e| e == "php"))
        .collect();
    let width = std::thread::available_parallelism().map_or(4, |n| n.get().min(8));
    for batch in php.chunks(width) {
        let mut children = Vec::new();
        for path in batch {
            let child = std::process::Command::new("php")
                .arg("-l")
                .arg(path)
                .stdin(std::process::Stdio::null())
                .stdout(std::process::Stdio::piped())
                .stderr(std::process::Stdio::piped())
                .spawn();
            match child {
                Ok(child) => children.push(child),
                Err(_) => return Ok(()), // no PHP on this machine: skip the lint
            }
        }
        let mut failure = None;
        for child in children {
            let o = child.wait_with_output().map_err(|e| e.to_string())?;
            if !o.status.success() && failure.is_none() {
                failure = Some(format!(
                    "{}{}",
                    String::from_utf8_lossy(&o.stdout),
                    String::from_utf8_lossy(&o.stderr)
                ));
            }
        }
        if let Some(failure) = failure {
            return Err(failure);
        }
    }
    Ok(())
}

/// Removes a generated plugin's folder and ZIP, if there.
fn remove_plugin(directory: &Path) -> std::io::Result<()> {
    if directory.exists() {
        std::fs::remove_dir_all(directory)?;
    }
    let archive = directory.with_extension("zip");
    if archive.exists() {
        std::fs::remove_file(archive)?;
    }
    Ok(())
}

/// Whether a runtime plugin's folder holds exactly the paths, and the sizes, this compiler writes,
/// and its ZIP is there. A cheap check: an edit that keeps a file's size is not seen.
fn runtime_plugin_intact(directory: &Path, slug: &str) -> std::io::Result<bool> {
    if !directory.is_dir() || !directory.with_extension("zip").is_file() {
        return Ok(false);
    }
    let prefix = format!("{slug}/");
    let mut expected: Vec<(String, u64)> = runtime_tree()
        .into_iter()
        .filter_map(|(rel, bytes)| {
            rel.strip_prefix(&prefix)
                .map(|rest| (rest.to_string(), bytes.len() as u64))
        })
        .collect();
    expected.sort();
    let mut found = Vec::new();
    if listing(directory, directory, &mut found).is_err() {
        return Ok(false);
    }
    found.sort();
    Ok(found == expected)
}

/// Every regular file below `dir` as (`/`-separated path below `root`, size). A symlink or special
/// file is an error.
fn listing(root: &Path, dir: &Path, out: &mut Vec<(String, u64)>) -> std::io::Result<()> {
    for entry in std::fs::read_dir(dir)? {
        let entry = entry?;
        let kind = entry.file_type()?;
        let path = entry.path();
        if kind.is_dir() {
            listing(root, &path, out)?;
        } else if kind.is_file() {
            let rel = path
                .strip_prefix(root)
                .expect("under root")
                .to_string_lossy()
                .replace('\\', "/");
            out.push((rel, entry.metadata()?.len()));
        } else {
            return Err(std::io::Error::other(format!(
                "{} is not a regular file",
                path.display()
            )));
        }
    }
    Ok(())
}

/// Brings the app plugin's folder to exactly `files` (paths below the plugins directory): a file
/// with the same bytes stays, a changed one is written, one no longer generated is removed. Returns
/// the files written; any removal also counts as a change (an empty entry), so the ZIP is redone.
fn write_app_plugin(plugins: &Path, directory: &Path, files: &[(String, Vec<u8>)]) -> std::io::Result<Vec<PathBuf>> {
    let wanted: std::collections::BTreeSet<PathBuf> = files.iter().map(|(rel, _)| plugins.join(rel)).collect();
    let mut changed = Vec::new();
    if directory.exists() {
        let mut existing = Vec::new();
        if listing(directory, directory, &mut existing).is_err() {
            // Not a tree TypedPress wrote: start it again.
            std::fs::remove_dir_all(directory)?;
        } else {
            for (rel, _) in existing {
                let path = directory.join(&rel);
                if !wanted.contains(&path) {
                    std::fs::remove_file(&path)?;
                    changed.push(PathBuf::new());
                }
            }
            remove_empty_dirs(directory)?;
        }
    }
    // The migrations directory exists even when the ledger has no SQL yet, as it always has.
    std::fs::create_dir_all(directory.join("tp/app/migrations"))?;
    for (rel, bytes) in files {
        let path = plugins.join(rel);
        if write(&path, bytes)? {
            changed.push(path);
        }
    }
    Ok(changed)
}

fn remove_empty_dirs(dir: &Path) -> std::io::Result<bool> {
    let mut empty = true;
    for entry in std::fs::read_dir(dir)? {
        let entry = entry?;
        if entry.file_type()?.is_dir() && remove_empty_dirs(&entry.path())? {
            std::fs::remove_dir(entry.path())?;
        } else {
            empty = false;
        }
    }
    Ok(empty)
}

/// What `typedpress check --json` answers: the `typedpress-diagnostics` envelope, and the build when it has
/// no diagnostics. Compiles in memory and writes nothing.
pub(crate) fn check(project: &Path, frozen: bool) -> (serde_json::Value, Option<Built>) {
    let built = match loader::load_all(&project.join("typedpress.config.ts")) {
        Err(diagnostics) => Err(diagnostics),
        Ok(program) => build_program(project, &program, frozen).map_err(|d| vec![d]),
    };
    let mut envelope = diag::envelope(built.as_ref().err().map_or(&[], Vec::as_slice), project);
    if let Ok(built) = &built {
        envelope = envelope.with_indexes(&built.snapshot);
    }
    let mut envelope = serde_json::to_value(envelope).expect("diagnostic envelope serializes");
    // Each plugin's package, source integrity, locked and inferred grant, and read→send flows.
    if let Ok(built) = &built
        && built.plugin_report.as_array().is_some_and(|p| !p.is_empty())
    {
        envelope["plugins"] = built.plugin_report.clone();
    }
    (envelope, built.ok())
}

/// CI never approves a plugin grant: the committed lock is the only one.
pub(crate) fn frozen(flag: bool) -> bool {
    flag || std::env::var_os("CI").is_some_and(|v| !v.is_empty() && v != "false")
}

pub(super) fn run(args: &crate::cli::Args) -> ExitCode {
    let project = args.project.canonicalize().unwrap_or(args.project.clone());
    if !matches!(args.command.as_str(), "build" | "check") {
        eprintln!("no command {}\n{}", args.command, crate::cli::help());
        return ExitCode::from(2);
    }
    let frozen = frozen(args.frozen);
    if args.command == "check" && args.json {
        let (envelope, built) = check(&project, frozen);
        println!("{envelope}");
        return if built.is_some() {
            ExitCode::SUCCESS
        } else {
            ExitCode::from(1)
        };
    }
    let started = std::time::Instant::now();
    let built = match build(&project, frozen) {
        Ok(b) => b,
        Err(d) => {
            eprint!("{}", diag::render(&d, &project));
            return ExitCode::from(1);
        }
    };
    for line in &built.plugin_changes {
        eprintln!("{line}");
    }
    for w in &built.unsupported_plugins {
        eprintln!("warning: {w}");
    }
    // A project that ran `typedpress init`: its agent files follow the config and the compiler.
    let agent_files = (!args.quiet)
        .then(|| super::init::stale_hint(&project, &built.schema))
        .flatten();
    if args.command == "check" {
        if !args.quiet {
            for h in built.hints.iter().chain(&agent_files) {
                eprintln!("hint: {h}");
            }
        }
        println!("ok: {} ({:.0?})", built.summary(), started.elapsed());
        return ExitCode::SUCCESS;
    }

    // Dev supplies its last successfully applied snapshot. Check the exact candidate before
    // emitting either a migration or a snapshot, so a rejected save cannot poison the next build.
    if let Some(path) = &args.preserve_data {
        let previous =
            match std::fs::read(path).and_then(|bytes| serde_json::from_slice(&bytes).map_err(std::io::Error::other)) {
                Ok(previous) => previous,
                Err(error) => {
                    eprintln!("error: can't read preservation snapshot {}: {error}", path.display());
                    return ExitCode::from(1);
                }
            };
        let problems = crate::migrate::preservation_problems(&previous, &built.snapshot);
        if !problems.is_empty() {
            eprintln!(
                "error: {}. Local data was preserved. Review migrations, then restart once with --allow-destructive to apply deliberately.",
                problems.join("; ")
            );
            return ExitCode::from(1);
        }
    }

    if let Err(e) = emit_project(&project, &built) {
        eprintln!("error: couldn't write migrations or the client: {e}");
        return ExitCode::from(1);
    }
    // A project that syncs commits its sync bases, and nothing else under .tp (design §5.4).
    if built.schema.collections.values().any(|c| c.sync.is_some())
        && let Err(e) = crate::sync::git::ignore_build_output(&project)
    {
        eprintln!("error: {e}");
        return ExitCode::from(1);
    }
    let root = args.out.clone().unwrap_or_else(|| project.join(layout::WP_CONTENT));
    let out = match emit_wp_content(&project, &root, &built) {
        Ok(directory) => directory,
        Err(error) => {
            eprintln!("error: couldn't write wp-content: {error}");
            return ExitCode::from(1);
        }
    };
    for w in &built.warnings {
        eprintln!("warning: this migration {w}");
    }
    if !args.quiet {
        for h in built.hints.iter().chain(&agent_files) {
            eprintln!("hint: {h}");
        }
    }
    let shown = out.strip_prefix(&project).unwrap_or(&out).display().to_string();
    let new: Vec<String> = built
        .migrations
        .iter()
        .map(|(n, _)| format!("{}/{n}", pipeline::LEDGER))
        .collect();
    let suffix = if new.is_empty() {
        String::new()
    } else {
        format!(" (new migrations {})", new.join(", "))
    };
    println!(
        "built {} -> {shown}{suffix} in {:.0?}",
        built.summary(),
        started.elapsed()
    );
    ExitCode::SUCCESS
}

#[cfg(test)]
mod tests {
    use super::read_previous;

    #[test]
    fn a_snapshot_that_cannot_be_read_is_an_error_not_a_first_build() {
        let project = tempfile::tempdir().unwrap();
        // A directory where the file should be: readable as a path, not as a snapshot.
        std::fs::create_dir_all(project.path().join(crate::pipeline::LEDGER).join("snapshot.json")).unwrap();
        let error = read_previous(project.path()).unwrap_err();
        assert!(
            error
                .message
                .starts_with("can't read tp/_generated/migrations/snapshot.json: "),
            "{}",
            error.message
        );
    }

    #[test]
    // APFS rejects this fixture at creation; Linux permits the filename the reader must refuse.
    #[cfg(target_os = "linux")]
    fn a_migration_file_name_that_is_not_utf8_is_an_error_not_skipped() {
        use std::os::unix::ffi::OsStrExt as _;
        let project = tempfile::tempdir().unwrap();
        let dir = project.path().join(crate::pipeline::LEDGER);
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("0001_init.sql"), "").unwrap();
        std::fs::write(dir.join(std::ffi::OsStr::from_bytes(b"0002_\xff.sql")), "").unwrap();
        let error = read_previous(project.path()).unwrap_err();
        assert!(error.message.contains("is not a UTF-8 file name"), "{}", error.message);
    }

    #[test]
    fn a_new_migration_never_replaces_a_file_that_is_already_there() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join(crate::pipeline::LEDGER).join("0002_update.sql");
        super::write_new(&path, "first").unwrap();
        let error = super::write_new(&path, "second").unwrap_err();
        assert_eq!(error.kind(), std::io::ErrorKind::AlreadyExists);
        assert_eq!(std::fs::read_to_string(&path).unwrap(), "first");
    }

    #[test]
    fn a_ledger_still_at_the_old_path_is_refused_not_built_as_a_first_build() {
        let project = tempfile::tempdir().unwrap();
        std::fs::create_dir_all(project.path().join("tp/migrations")).unwrap();
        std::fs::write(project.path().join("tp/migrations/snapshot.json"), "{}").unwrap();
        let error = read_previous(project.path()).unwrap_err();
        assert_eq!(error.message, "tp/migrations/ moved to tp/_generated/migrations/");
    }

    /// Every changed file is linted, not only the first: a syntax error anywhere fails the build.
    #[test]
    fn php_lint_reports_a_syntax_error_in_any_file() {
        if std::process::Command::new("php").arg("-v").output().is_err() {
            return;
        }
        let dir = tempfile::tempdir().unwrap();
        let mut files = Vec::new();
        for i in 0..20 {
            let path = dir.path().join(format!("f{i}.php"));
            std::fs::write(&path, if i == 13 { "<?php echo ;\n" } else { "<?php echo 1;\n" }).unwrap();
            files.push(path);
        }
        files.push(dir.path().join("readme.txt"));
        let error = super::php_lint(&files).unwrap_err();
        assert!(error.contains("f13.php"), "{error}");
        files.remove(13);
        super::php_lint(&files).unwrap();
    }

    #[test]
    fn a_missing_snapshot_is_a_first_build() {
        let project = tempfile::tempdir().unwrap();
        let previous = read_previous(project.path()).unwrap();
        assert_eq!(previous.next_migration, 1);
        assert_eq!(previous.snapshot["tables"], serde_json::json!({}));
    }
}
