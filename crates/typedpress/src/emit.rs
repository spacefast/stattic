use crate::app_plugin::{readme, substitute};
use crate::{pipeline::Built, platform, runtime_files};
use std::path::Path;

/// Writes `content` to `path` unless the file already holds exactly those bytes, so an unchanged
/// file keeps its modification time; returns whether it wrote. A changed file is removed and
/// written again rather than overwritten in place: `typedpress build` stages its output with hard links
/// to the published files, and writing through a link would change the published copy before the
/// stage is published.
pub(crate) fn write(path: &Path, content: impl AsRef<[u8]>) -> std::io::Result<bool> {
    let content = content.as_ref();
    match std::fs::symlink_metadata(path) {
        Ok(meta) if meta.is_file() => {
            if meta.len() == content.len() as u64 && std::fs::read(path)? == content {
                return Ok(false);
            }
            std::fs::remove_file(path)?;
        }
        Ok(_) => {}
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => {
            if let Some(dir) = path.parent() {
                std::fs::create_dir_all(dir)?;
            }
        }
        Err(e) => return Err(e),
    }
    std::fs::write(path, content)?;
    Ok(true)
}

/// Every file under `root`, by `/`-separated path below it, sorted.
fn read_tree(root: &Path) -> std::io::Result<Vec<(String, Vec<u8>)>> {
    fn walk(root: &Path, dir: &Path, out: &mut Vec<(String, Vec<u8>)>) -> std::io::Result<()> {
        let mut entries = std::fs::read_dir(dir)?.collect::<Result<Vec<_>, _>>()?;
        entries.sort_by_key(|entry| entry.file_name());
        for entry in entries {
            let path = entry.path();
            if entry.file_type()?.is_dir() {
                walk(root, &path, out)?;
            } else {
                let rel = path
                    .strip_prefix(root)
                    .expect("under root")
                    .to_string_lossy()
                    .replace('\\', "/");
                out.push((rel, std::fs::read(&path)?));
            }
        }
        Ok(())
    }
    let mut out = Vec::new();
    walk(root, root, &mut out)?;
    Ok(out)
}

/// The ledger's `.sql` files, by name.
fn read_sql(dir: &Path) -> std::io::Result<Vec<(String, Vec<u8>)>> {
    let mut out = Vec::new();
    if dir.is_dir() {
        for entry in std::fs::read_dir(dir)? {
            let entry = entry?;
            if entry.path().extension().is_some_and(|e| e == "sql") {
                out.push((
                    entry.file_name().to_string_lossy().into_owned(),
                    std::fs::read(entry.path())?,
                ));
            }
        }
    }
    Ok(out)
}

pub(crate) fn runtime_file(rel: &str) -> Option<&'static [u8]> {
    runtime_files::FILES.iter().find(|(p, _)| *p == rel).map(|(_, b)| *b)
}

/// The embedded files below `prefix` (a directory of `runtime/`), as (path below it, bytes).
#[cfg(feature = "cli")]
pub(crate) fn embedded(prefix: &'static str) -> impl Iterator<Item = (&'static str, &'static [u8])> {
    runtime_files::FILES
        .iter()
        .filter_map(move |(rel, bytes)| rel.strip_prefix(prefix).map(|rest| (rest, *bytes)))
}

/// The schema-independent plugins: `typedpress-runtime`, `typedpress-identity` and `typedpress-admin`, as
/// (path below the plugins directory, bytes). Identical for every app built by this compiler, so a
/// build store writes the tree once per revision.
pub(crate) fn runtime_tree() -> Vec<(String, std::borrow::Cow<'static, [u8]>)> {
    use std::borrow::Cow;
    // Built once per process: hashing and substituting it is the same work on every call.
    static TREE: std::sync::OnceLock<Vec<(String, Cow<'static, [u8]>)>> = std::sync::OnceLock::new();
    TREE.get_or_init(build_runtime_tree).clone()
}

/// Pack the browser entry modules with the same minifier used for app client modules.
fn embedded_runtime() -> impl Iterator<Item = (&'static str, std::borrow::Cow<'static, [u8]>)> {
    use std::borrow::Cow;

    runtime_files::FILES
        .iter()
        .filter(|(rel, _)| rel.starts_with("TypedPress/"))
        .map(|(rel, bytes)| {
            let bytes = if rel.starts_with("TypedPress/Render/client/") && rel.ends_with(".js") {
                let source = std::str::from_utf8(bytes).expect("runtime browser module is UTF-8");
                Cow::Owned(crate::minify::module(source).into_bytes())
            } else {
                Cow::Borrowed(*bytes)
            };
            (*rel, bytes)
        })
}

fn build_runtime_tree() -> Vec<(String, std::borrow::Cow<'static, [u8]>)> {
    use std::borrow::Cow;
    let runtime: Vec<_> = embedded_runtime().collect();
    let runtime_hash = {
        use sha2::Digest;
        let mut hash = sha2::Sha256::new();
        for (rel, bytes) in &runtime {
            hash.update(rel.as_bytes());
            hash.update([0]);
            hash.update(bytes.as_ref());
        }
        crate::hex(&hash.finalize()[..8])
    };
    let vars = [
        ("runtimeHash", runtime_hash),
        ("wpVersion", platform::WORDPRESS.to_string()),
        ("phpVersion", platform::PHP.to_string()),
        ("tpVersion", env!("CARGO_PKG_VERSION").to_string()),
        // Every platform plugin Runtime::activate() requires; Polylang only with an app's `locales`.
        (
            "runtimeRequires",
            platform::plugins(false)
                .into_iter()
                .map(|p| p.slug)
                .collect::<Vec<_>>()
                .join(", "),
        ),
    ];
    let template = runtime_file("wp-plugin/typedpress-runtime.php").expect("embedded plugin template");
    let uninstall = runtime_file("wp-plugin/typedpress-runtime-uninstall.php").expect("embedded uninstall template");
    let mut files: Vec<(String, Cow<'static, [u8]>)> = vec![
        (
            "typedpress-runtime/typedpress-runtime.php".into(),
            Cow::Owned(substitute(&String::from_utf8_lossy(template), &vars).into_bytes()),
        ),
        // Deleting the plugin in wp-admin runs uninstall.php: options and capabilities go, content stays.
        ("typedpress-runtime/uninstall.php".into(), Cow::Borrowed(uninstall)),
        (
            "typedpress-runtime/readme.txt".into(),
            Cow::Owned(readme("typedpress-runtime").into_bytes()),
        ),
        // The build store's loader, which the host requires to mount one build.
        (
            "typedpress-loader.php".into(),
            Cow::Borrowed(runtime_file("wp-plugin/typedpress-loader.php").expect("embedded loader")),
        ),
        (
            "typedpress-commerce.php".into(),
            Cow::Borrowed(runtime_file("wp-plugin/typedpress-commerce.php").expect("embedded commerce bootstrap")),
        ),
    ];
    files.extend(runtime.into_iter().map(|(rel, bytes)| {
        let rest = rel.strip_prefix("TypedPress/").expect("TypedPress runtime tree");
        (format!("typedpress-runtime/tp/{rest}"), bytes)
    }));
    for (rel, bytes) in runtime_files::FILES {
        if let Some(rest) = rel.strip_prefix("identity/") {
            files.push((format!("typedpress-identity/{rest}"), Cow::Borrowed(*bytes)));
        } else if let Some(rest) = rel.strip_prefix("admin/") {
            // The committed admin plugin (PHP, routes.js, notices, the built modules); its header
            // carries the same version placeholders as the generated plugins.
            let bytes = if rest == "typedpress-admin.php" {
                Cow::Owned(substitute(&String::from_utf8_lossy(bytes), &vars).into_bytes())
            } else {
                Cow::Borrowed(*bytes)
            };
            files.push((format!("typedpress-admin/{rest}"), bytes));
        }
    }
    files
}

/// Generated plugins are prepared in the same private tree as acquired dependencies. Without
/// `runtime`, only the app plugin is written (the build store holds the runtime tree separately).
pub(crate) fn emit_generated(
    project: &Path,
    migrations: &Path,
    plugins: &Path,
    built: &Built,
    runtime: bool,
) -> std::io::Result<()> {
    if runtime {
        let slugs = ["typedpress-runtime", "typedpress-identity", "typedpress-admin"];
        emit_runtime(plugins, &slugs[..if built.schema.admin { 3 } else { 2 }])?;
    }
    let (_, files) = app_files(project, migrations, built)?;
    // The migrations directory exists even when the ledger has no SQL yet, as it always has.
    std::fs::create_dir_all(plugins.join(format!("typedpress-app-{}/tp/app/migrations", built.schema.name)))?;
    for (rel, bytes) in files {
        write(&plugins.join(rel), bytes)?;
    }
    Ok(())
}

/// Writes the runtime plugins named in `slugs` (`typedpress-runtime`, `typedpress-identity`, `typedpress-admin`).
pub(crate) fn emit_runtime(plugins: &Path, slugs: &[&str]) -> std::io::Result<()> {
    for (rel, bytes) in runtime_tree() {
        if rel.split_once('/').is_some_and(|(slug, _)| slugs.contains(&slug)) {
            write(&plugins.join(rel), bytes)?;
        }
    }
    Ok(())
}

/// Files as (path, bytes).
pub(crate) type Files = Vec<(String, Vec<u8>)>;

/// The app plugin's files (path below the plugins directory, bytes), and the `schema.php` they
/// were made with.
pub(crate) fn app_files(project: &Path, migrations: &Path, built: &Built) -> std::io::Result<(String, Files)> {
    let (schema_php, mut files) = crate::app_plugin::package(built, read_sql(migrations)?, public_files(project)?);
    if built.schema.ships_seed() {
        let seeds = crate::bundle::seed_records(&crate::loader::Disk, project, &built.schema, true)
            .map_err(|e| std::io::Error::other(e.message))?;
        use sha2::{Digest, Sha256};
        let mut hashes = serde_json::Map::new();
        for (name, records) in seeds {
            let php = format!("<?php\nreturn {};\n", crate::compile::php_json(&records));
            hashes.insert(format!("{name}.php"), crate::hex(Sha256::digest(php.as_bytes())).into());
            files.push((
                format!("typedpress-app-{}/tp/app/seed/{name}.php", built.schema.name),
                php.into_bytes(),
            ));
        }
        let manifest = crate::compile::php_json(&serde_json::Value::Object(hashes));
        files.push((
            format!("typedpress-app-{}/tp/app/seed-version.php", built.schema.name),
            format!("<?php return {manifest};\n").into_bytes(),
        ));
    }
    Ok((schema_php, files))
}

/// The public files carried in the app plugin, with paths relative to `public/`.
pub(crate) fn public_files(project: &Path) -> std::io::Result<Files> {
    let public = project.join("public");
    if public.is_dir() {
        read_tree(&public)
    } else {
        Ok(Vec::new())
    }
}

#[cfg(test)]
mod tests {
    use super::write;

    #[test]
    fn write_leaves_a_file_with_the_same_bytes_alone() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("a/b.txt");
        assert!(write(&path, "one").unwrap(), "a new file is written");
        let old = std::time::SystemTime::UNIX_EPOCH + std::time::Duration::from_secs(1_000_000);
        std::fs::File::options()
            .write(true)
            .open(&path)
            .unwrap()
            .set_modified(old)
            .unwrap();
        assert!(!write(&path, "one").unwrap(), "the same bytes are not written again");
        assert_eq!(std::fs::metadata(&path).unwrap().modified().unwrap(), old);
        assert!(write(&path, "two").unwrap());
        assert_eq!(std::fs::read_to_string(&path).unwrap(), "two");
        assert_ne!(std::fs::metadata(&path).unwrap().modified().unwrap(), old);
    }

    /// A staged build links unchanged files from the published output; a changed file gets a new
    /// file, so the published copy keeps its bytes until the stage replaces the output.
    #[test]
    fn write_replaces_a_linked_file_instead_of_writing_through_the_link() {
        let dir = tempfile::tempdir().unwrap();
        let published = dir.path().join("published.txt");
        let staged = dir.path().join("staged.txt");
        std::fs::write(&published, "old").unwrap();
        std::fs::hard_link(&published, &staged).unwrap();
        assert!(write(&staged, "new").unwrap());
        assert_eq!(std::fs::read_to_string(&staged).unwrap(), "new");
        assert_eq!(std::fs::read_to_string(&published).unwrap(), "old");
    }
}
