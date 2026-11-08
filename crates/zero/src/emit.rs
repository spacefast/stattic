use crate::{pipeline::Built, platform, runtime_files, schema, schema_php};
use std::path::Path;

pub(crate) fn write(path: &Path, content: impl AsRef<[u8]>) -> std::io::Result<()> {
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir)?;
    }
    std::fs::write(path, content)
}

fn copy_dir(from: &Path, to: &Path) -> std::io::Result<()> {
    std::fs::create_dir_all(to)?;
    for entry in std::fs::read_dir(from)? {
        let entry = entry?;
        let target = to.join(entry.file_name());
        if entry.file_type()?.is_dir() {
            std::fs::create_dir_all(&target)?;
            copy_dir(&entry.path(), &target)?;
        } else {
            std::fs::copy(entry.path(), target)?;
        }
    }
    Ok(())
}

fn copy_sql(from: &Path, to: &Path) -> std::io::Result<()> {
    std::fs::create_dir_all(to)?;
    if from.is_dir() {
        for entry in std::fs::read_dir(from)? {
            let entry = entry?;
            if entry.path().extension().is_some_and(|e| e == "sql") {
                std::fs::copy(entry.path(), to.join(entry.file_name()))?;
            }
        }
    }
    Ok(())
}

pub(crate) fn runtime_file(rel: &str) -> Option<&'static [u8]> {
    runtime_files::FILES.iter().find(|(p, _)| *p == rel).map(|(_, b)| *b)
}

/// The schema-independent plugins: `zero-runtime`, `zero-identity` and `zero-admin`, as
/// (path below the plugins directory, bytes). Identical for every app built by this compiler, so a
/// build store writes the tree once per revision.
pub(crate) fn runtime_tree() -> Vec<(String, std::borrow::Cow<'static, [u8]>)> {
    use std::borrow::Cow;
    let vars = [
        ("wpVersion", platform::WORDPRESS.to_string()),
        ("phpVersion", platform::PHP.to_string()),
        ("zeroVersion", env!("CARGO_PKG_VERSION").to_string()),
    ];
    let template = runtime_file("wp-plugin/zero-runtime.php").expect("embedded plugin template");
    let uninstall = runtime_file("wp-plugin/zero-runtime-uninstall.php").expect("embedded uninstall template");
    let mut files: Vec<(String, Cow<'static, [u8]>)> = vec![
        (
            "zero-runtime/zero-runtime.php".into(),
            Cow::Owned(substitute(&String::from_utf8_lossy(template), &vars).into_bytes()),
        ),
        // Deleting the plugin in wp-admin runs uninstall.php: options and capabilities go, content stays.
        ("zero-runtime/uninstall.php".into(), Cow::Borrowed(uninstall)),
        (
            "zero-runtime/readme.txt".into(),
            Cow::Owned(readme("zero-runtime").into_bytes()),
        ),
        // The build store's loader, which the host requires to mount one build.
        (
            "zero-loader.php".into(),
            Cow::Borrowed(runtime_file("wp-plugin/zero-loader.php").expect("embedded loader")),
        ),
    ];
    for (rel, bytes) in runtime_files::FILES {
        if let Some(rest) = rel.strip_prefix("Zero/") {
            files.push((format!("zero-runtime/zero/{rest}"), Cow::Borrowed(*bytes)));
        } else if let Some(rest) = rel.strip_prefix("identity/") {
            files.push((format!("zero-identity/{rest}"), Cow::Borrowed(*bytes)));
        } else if let Some(rest) = rel.strip_prefix("admin/") {
            // The committed admin plugin (PHP, routes.js, notices, the built modules); its header
            // carries the same version placeholders as the generated plugins.
            let bytes = if rest == "zero-admin.php" {
                Cow::Owned(substitute(&String::from_utf8_lossy(bytes), &vars).into_bytes())
            } else {
                Cow::Borrowed(*bytes)
            };
            files.push((format!("zero-admin/{rest}"), bytes));
        }
    }
    files
}

fn readme(slug: &str) -> String {
    format!(
        "=== {slug} ===\nStable tag: {}\nLicense: GPL-2.0-or-later\n",
        env!("CARGO_PKG_VERSION")
    )
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
    let schema_php = schema_php::render(&built.schema);
    let vars = plugin_vars(&built.schema, &schema_php);
    if runtime {
        for (rel, bytes) in runtime_tree() {
            // The optional admin screens: only when the config opted in.
            if rel != "zero-loader.php" && (built.schema.admin || !rel.starts_with("zero-admin/")) {
                write(&plugins.join(rel), bytes)?;
            }
        }
    }
    let slug = format!("zero-app-{}", built.schema.name);
    let directory = plugins.join(&slug);
    std::fs::create_dir_all(&directory)?;
    let template = runtime_file("wp-plugin/zero-app.php").expect("embedded plugin template");
    write(
        &directory.join(format!("{slug}.php")),
        substitute(&String::from_utf8_lossy(template), &vars),
    )?;
    let uninstall = runtime_file("wp-plugin/zero-app-uninstall.php").expect("embedded uninstall template");
    write(&directory.join("uninstall.php"), uninstall)?;
    write(&directory.join("readme.txt"), readme(&slug))?;
    // The app plugin declares GPL-2.0-or-later like the runtime it extends; carry the text.
    if let Some(license) = runtime_file("Zero/LICENSE") {
        write(&directory.join("LICENSE"), license)?;
    }
    write(&directory.join("zero/app/schema.php"), &schema_php)?;
    if let Some(wpml) = crate::wpml_config::render(&built.schema) {
        write(&directory.join("wpml-config.xml"), wpml)?;
    }
    write(&directory.join("zero/app/functions.php"), &built.functions_php)?;
    copy_sql(migrations, &directory.join("zero/app/migrations"))?;
    let public = project.join("public");
    if public.is_dir() {
        copy_dir(&public, &directory.join("public"))?;
    }
    Ok(())
}

pub(crate) fn plugin_vars(schema: &schema::Schema, schema_php: &str) -> Vec<(&'static str, String)> {
    let hash = {
        // FNV-1a: a stable fingerprint of the built schema (changes when the plugin must migrate).
        let mut h: u64 = 0xcbf2_9ce4_8422_2325;
        for b in schema_php.bytes() {
            h ^= u64::from(b);
            h = h.wrapping_mul(0x0100_0000_01b3);
        }
        format!("{h:016x}")
    };
    let label = schema::title_case(&schema.name);
    vec![
        ("name", schema.name.clone()),
        ("slug", format!("zero-app-{}", schema.name)),
        ("runtimeSeries", platform::SERIES.to_string()),
        ("wpVersion", platform::WORDPRESS.to_string()),
        ("phpVersion", platform::PHP.to_string()),
        (
            "requires",
            if schema.locales.is_some() {
                "secure-custom-fields, action-scheduler, mcp-adapter, zero-runtime, polylang"
            } else {
                "secure-custom-fields, action-scheduler, mcp-adapter, zero-runtime"
            }
            .to_string(),
        ),
        ("label", label.clone()),
        ("Name", label),
        ("version", env!("CARGO_PKG_VERSION").to_string()),
        ("schemaHash", hash),
        ("zeroVersion", env!("CARGO_PKG_VERSION").to_string()),
    ]
}

/// `{{key}}` → value. Unknown placeholders are left as they are.
fn substitute(text: &str, vars: &[(&'static str, String)]) -> String {
    let mut out = text.to_string();
    for (k, v) in vars {
        out = out.replace(&format!("{{{{{k}}}}}"), v);
    }
    out
}
