use crate::diag::{Diag, Span};
use crate::emit::{emit_generated, plugin_vars, runtime_file, write};
use crate::pipeline::{self, Built};
use crate::{diag, loader, platform, schema_php, wp_plugins};
use std::path::{Path, PathBuf};
use std::process::ExitCode;

/// Runs the whole pipeline in memory.
fn build(project: &Path, frozen: bool) -> Result<Built, Diag> {
    let entry = project.join("zero.config.ts");
    if !entry.is_file() {
        return Err(Diag::new(
            Span::NONE,
            format!("no zero.config.ts in {}", project.display()),
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
    project.join("zero").join("migrations")
}

/// The last build, from `zero/migrations/`: the snapshot and everything beside it.
fn read_previous(project: &Path) -> Result<pipeline::Previous, Diag> {
    let dir = migrations_dir(project);
    // Only a missing file is a first build. A snapshot that is there but cannot be read (permissions,
    // a directory in its place, not UTF-8) is an error: diffing against an empty schema would plan a
    // migration that recreates every table.
    let snapshot = match std::fs::read_to_string(dir.join("snapshot.json")) {
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => None,
        Err(e) => {
            return Err(Diag::new(
                Span::NONE,
                format!("can't read zero/migrations/snapshot.json: {e}"),
            ));
        }
        Ok(text) => Some(serde_json::from_str(&text).map_err(|e| {
            Diag::new(
                Span::NONE,
                format!("zero/migrations/snapshot.json is not valid JSON: {e}"),
            )
        })?),
    };
    let names = migration_names(&dir)?;
    pipeline::Previous::from_project(snapshot, names.iter().map(String::as_str))
}

/// Every name in `zero/migrations/`. The next migration's number comes from these, so an entry that
/// cannot be listed or named is an error: skipping it could reuse a number that is already taken.
fn migration_names(dir: &Path) -> Result<Vec<String>, Diag> {
    let entries = match std::fs::read_dir(dir) {
        Ok(entries) => entries,
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => return Ok(Vec::new()),
        Err(e) => return Err(Diag::new(Span::NONE, format!("can't read zero/migrations/: {e}"))),
    };
    let mut names = Vec::new();
    for entry in entries {
        let entry = entry.map_err(|e| Diag::new(Span::NONE, format!("can't read zero/migrations/: {e}")))?;
        let name = entry.file_name();
        let Some(name) = name.to_str() else {
            return Err(Diag::new(
                Span::NONE,
                format!(
                    "zero/migrations/{} is not a UTF-8 file name; rename or remove it",
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

/// Writes migrations, the snapshot, zero.gen.ts and the typed client into the project (committed).
fn emit_project(project: &Path, built: &Built) -> std::io::Result<()> {
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
    write(&project.join("zero.gen.ts"), &built.gen_ts)?;
    if let Some(lock) = &built.plugin_lock {
        write(
            &project.join(crate::plugins::LOCK_PATH),
            serde_json::to_string_pretty(lock)? + "\n",
        )?;
    }
    let client_dir = project.join("zero").join("client");
    for (name, content) in &built.client_files {
        write(&client_dir.join(name), content)?;
    }
    Ok(())
}

fn zip_plugin(directory: &Path) -> std::io::Result<()> {
    use std::io::Write;
    fn append(writer: &mut zip::ZipWriter<std::fs::File>, root: &Path, dir: &Path) -> std::io::Result<()> {
        let mut entries = std::fs::read_dir(dir)?.collect::<Result<Vec<_>, _>>()?;
        entries.sort_by_key(|entry| entry.file_name());
        for entry in entries {
            let path = entry.path();
            if entry.file_type()?.is_dir() {
                append(writer, root, &path)?;
            } else if entry.file_type()?.is_file() {
                writer
                    .start_file(
                        path.strip_prefix(root).unwrap().to_string_lossy(),
                        zip::write::SimpleFileOptions::default().compression_method(zip::CompressionMethod::Deflated),
                    )
                    .map_err(std::io::Error::other)?;
                writer.write_all(&std::fs::read(&path)?)?;
            } else {
                return Err(std::io::Error::other(
                    "plugin trees cannot contain symlinks or special files",
                ));
            }
        }
        Ok(())
    }
    let mut writer = zip::ZipWriter::new(std::fs::File::create(directory.with_extension("zip"))?);
    append(&mut writer, directory.parent().unwrap(), directory)?;
    writer.finish().map_err(std::io::Error::other)?;
    Ok(())
}

/// Stage the complete artifact, publish it, then persist the sole dependency lock.
fn emit_wp_content(project: &Path, root: &Path, built: &Built) -> std::io::Result<PathBuf> {
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
    if project.starts_with(&root) || project.join("zero").starts_with(&root) {
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
    let lock = project.join("zero/wp-plugins.lock.json");
    std::fs::create_dir_all(lock.parent().unwrap())?;
    // Serialize the project lockfile and publication to a shared output root.
    let _project_guard = guard(project.join("zero/.wp-plugins.build.lock"))?;
    let _output_guard = guard(parent.join(format!(
        ".{}.zero-build.lock",
        root.file_name().unwrap().to_string_lossy()
    )))?;
    let prepared = wp_plugins::Prepared::acquire(&plan, &lock, &platform::cache(project))?;
    let temp = tempfile::tempdir_in(&parent)?;
    let stage = temp.path().join("wp-content");
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
    let mut generated = vec![
        "zero-runtime".to_string(),
        "zero-identity".to_string(),
        format!("zero-app-{}", built.schema.name),
    ];
    if built.schema.admin {
        generated.push("zero-admin".to_string());
    }
    // Admin is also owned here when its opt-in is removed.
    for slug in generated
        .iter()
        .map(String::as_str)
        .chain((!built.schema.admin).then_some("zero-admin"))
    {
        let directory = plugins.join(slug);
        if directory.exists() {
            std::fs::remove_dir_all(&directory)?;
        }
        let archive = directory.with_extension("zip");
        if archive.exists() {
            std::fs::remove_file(archive)?;
        }
    }
    emit_generated(project, &migrations_dir(project), &plugins, built, true)?;
    let mut versions = serde_json::Map::new();
    for slug in generated {
        let directory = plugins.join(&slug);
        php_lint_tree(&directory)?;
        zip_plugin(&directory)?;
        let version = if slug.starts_with("zero-app-") {
            format!(
                "{}+{}",
                env!("CARGO_PKG_VERSION"),
                plugin_vars(&built.schema, &schema_php::render(&built.schema))
                    .into_iter()
                    .find(|(key, _)| *key == "schemaHash")
                    .unwrap()
                    .1
            )
        } else {
            env!("CARGO_PKG_VERSION").to_string()
        };
        versions.insert(slug, version.into());
    }
    for dependency in prepared.dependencies() {
        versions.insert(dependency.slug.clone(), dependency.version.clone().into());
    }
    let requires: serde_json::Map<String, serde_json::Value> = platform::plugins(built.schema.locales.is_some())
        .into_iter()
        .map(|plugin| (plugin.slug, format!(">={}", plugin.version).into()))
        .collect();
    write(
        &stage.join("zero-manifest.json"),
        serde_json::to_string_pretty(
            &serde_json::json!({"zero":env!("CARGO_PKG_VERSION"),"runtime":platform::SERIES,"app":built.schema.name,"plugins":versions,"requires":requires,"dependencies":prepared.dependencies().collect::<Vec<_>>(),"locales":built.schema.locales}),
        )? + "\n",
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
    let legacy = project.join(".zero/wp");
    if !root.starts_with(&legacy) && legacy.is_dir() {
        std::fs::remove_dir_all(legacy)?;
    }
    Ok(root)
}

fn php_lint(files: &[PathBuf]) -> Result<(), String> {
    for path in files {
        let result = std::process::Command::new("php").arg("-l").arg(path).output();
        match result {
            Ok(o) if o.status.success() => {}
            Ok(o) => {
                return Err(format!(
                    "{}{}",
                    String::from_utf8_lossy(&o.stdout),
                    String::from_utf8_lossy(&o.stderr)
                ));
            }
            Err(_) => return Ok(()), // no PHP on this machine: skip the lint
        }
    }
    Ok(())
}

fn check_json(project: &Path, frozen: bool) -> ExitCode {
    let (diagnostics, plugins) = match loader::load_all(&project.join("zero.config.ts")) {
        Err(diagnostics) => (diagnostics, None),
        Ok(program) => match build_program(project, &program, frozen) {
            Ok(built) => (Vec::new(), Some(built.plugin_report)),
            Err(d) => (vec![d], None),
        },
    };
    let mut envelope =
        serde_json::to_value(diag::envelope(&diagnostics, project)).expect("diagnostic envelope serializes");
    // Each plugin's package, source integrity, locked and inferred grant, and read→send flows.
    if let Some(plugins) = plugins.filter(|p| p.as_array().is_some_and(|p| !p.is_empty())) {
        envelope["plugins"] = plugins;
    }
    println!("{envelope}");
    if diagnostics.is_empty() {
        ExitCode::SUCCESS
    } else {
        ExitCode::from(1)
    }
}

pub(super) fn run(args: &crate::cli::Args) -> ExitCode {
    let project = args.project.canonicalize().unwrap_or(args.project.clone());
    if !matches!(args.command.as_str(), "build" | "check") {
        eprintln!("no command {}\n{}", args.command, crate::cli::help());
        return ExitCode::from(2);
    }
    // CI never approves a grant: the committed lock is the only one.
    let frozen = args.frozen || std::env::var_os("CI").is_some_and(|v| !v.is_empty() && v != "false");
    if args.command == "check" && args.json {
        return check_json(&project, frozen);
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
    if args.command == "check" {
        if !args.quiet {
            for h in &built.hints {
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
    // A project that syncs commits its sync bases, and nothing else under .zero (design §5.4).
    if built.schema.collections.values().any(|c| c.sync.is_some())
        && let Err(e) = crate::sync::git::ignore_build_output(&project)
    {
        eprintln!("error: {e}");
        return ExitCode::from(1);
    }
    let root = args.out.clone().unwrap_or_else(|| project.join(".zero/wp-content"));
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
        for h in &built.hints {
            eprintln!("hint: {h}");
        }
    }
    let shown = out.strip_prefix(&project).unwrap_or(&out).display().to_string();
    let new: Vec<String> = built
        .migrations
        .iter()
        .map(|(n, _)| format!("zero/migrations/{n}"))
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

fn php_lint_tree(directory: &Path) -> std::io::Result<()> {
    let mut php = Vec::new();
    fn collect(directory: &Path, files: &mut Vec<PathBuf>) -> std::io::Result<()> {
        for entry in std::fs::read_dir(directory)? {
            let entry = entry?;
            if entry.file_type()?.is_dir() && entry.file_name() != "vendor-prefixed" {
                collect(&entry.path(), files)?;
            } else if entry.path().extension().is_some_and(|extension| extension == "php") {
                files.push(entry.path());
            }
        }
        Ok(())
    }
    collect(directory, &mut php)?;
    php_lint(&php).map_err(std::io::Error::other)
}

#[cfg(test)]
mod tests {
    use super::read_previous;

    #[test]
    fn a_snapshot_that_cannot_be_read_is_an_error_not_a_first_build() {
        let project = tempfile::tempdir().unwrap();
        // A directory where the file should be: readable as a path, not as a snapshot.
        std::fs::create_dir_all(project.path().join("zero/migrations/snapshot.json")).unwrap();
        let error = read_previous(project.path()).unwrap_err();
        assert!(
            error.message.starts_with("can't read zero/migrations/snapshot.json: "),
            "{}",
            error.message
        );
    }

    #[test]
    fn a_migration_file_name_that_is_not_utf8_is_an_error_not_skipped() {
        use std::os::unix::ffi::OsStrExt as _;
        let project = tempfile::tempdir().unwrap();
        let dir = project.path().join("zero/migrations");
        std::fs::create_dir_all(&dir).unwrap();
        std::fs::write(dir.join("0001_init.sql"), "").unwrap();
        std::fs::write(dir.join(std::ffi::OsStr::from_bytes(b"0002_\xff.sql")), "").unwrap();
        let error = read_previous(project.path()).unwrap_err();
        assert!(error.message.contains("is not a UTF-8 file name"), "{}", error.message);
    }

    #[test]
    fn a_new_migration_never_replaces_a_file_that_is_already_there() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("zero/migrations/0002_update.sql");
        super::write_new(&path, "first").unwrap();
        let error = super::write_new(&path, "second").unwrap_err();
        assert_eq!(error.kind(), std::io::ErrorKind::AlreadyExists);
        assert_eq!(std::fs::read_to_string(&path).unwrap(), "first");
    }

    #[test]
    fn a_missing_snapshot_is_a_first_build() {
        let project = tempfile::tempdir().unwrap();
        let previous = read_previous(project.path()).unwrap();
        assert_eq!(previous.next_migration, 1);
        assert_eq!(previous.snapshot["tables"], serde_json::json!({}));
    }
}
