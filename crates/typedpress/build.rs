//! Embeds the runtime into the binary: every file under `runtime/` (PHP runtime,
//! WordPress stores, plugin template, client runtime). New files are picked up
//! without code changes; `typedpress build` decides which go where.
//!
//! Also embeds the documentation `typedpress mcp` searches and reads (`docs_files.rs`): the guides and
//! references in `docs/*.md` and the docs site's pages in `docs-site/docs/`, so an answer matches
//! the binary that gave it. And the agent skills under `skills/`, which `typedpress init` writes into a
//! project.

use std::fmt::Write as _;
use std::path::Path;

fn collect(dir: &Path, root: &Path, out: &mut Vec<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    let mut entries: Vec<_> = entries.filter_map(Result::ok).collect();
    entries.sort_by_key(|e| e.file_name());
    for e in entries {
        let path = e.path();
        let name = e.file_name();
        let name = name.to_string_lossy();
        let rel = path.strip_prefix(root).expect("under runtime/");
        let vendor = rel.components().any(|part| part.as_os_str() == "vendor-prefixed");
        if (name.starts_with('.') && name != ".manifest.json")
            || matches!(
                name.as_ref(),
                "vendor"
                    | "node_modules"
                    | "apps"
                    // The admin's retained upstream inputs: provenance in the repository
                    // (runtime/admin/NOTICE, upstream-inventory.json), never loaded by the plugin.
                    | "upstream-metadata"
                    | "composer.json"
                    | "composer.lock"
                    | "vendor.sh"
                    | "vendor-metadata.php"
            )
            || (vendor
                && (name.eq_ignore_ascii_case("tests") || name.eq_ignore_ascii_case("docs") || name.ends_with(".md")))
        {
            continue;
        }
        if path.is_dir() {
            collect(&path, root, out);
        } else {
            let rel = path.strip_prefix(root).expect("under runtime/");
            out.push(rel.to_string_lossy().replace('\\', "/"));
        }
    }
}

/// The pages under `dir` with one of `extensions`, as paths relative to the repository root.
fn docs(dir: &Path, repo: &Path, recurse: bool, extensions: &[&str], out: &mut Vec<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    let mut entries: Vec<_> = entries.filter_map(Result::ok).collect();
    entries.sort_by_key(|e| e.file_name());
    for e in entries {
        let path = e.path();
        if path.is_dir() {
            if recurse {
                docs(&path, repo, recurse, extensions, out);
            }
        } else if path
            .extension()
            .is_some_and(|x| extensions.iter().any(|want| x == *want))
        {
            let rel = path.strip_prefix(repo).expect("under the repository");
            out.push(rel.to_string_lossy().replace('\\', "/"));
        }
    }
}

/// The `docs/*.md` pages `typedpress mcp` answers from: what someone building an app with TypedPress reads.
const GUIDES: &[&str] = &[
    "admin.md",
    "admin-screens.md",
    "agents.md",
    "astro.md",
    "class-compilation.md",
    "compiler-diagnostics.md",
    "config-api.md",
    "config-migration.md",
    "deployment.md",
    "examples.md",
    "typedpress-compatibility.md",
    "identity-api.md",
    "identity-security.md",
    "installation.md",
    "language-support.md",
    "migrating-from-zero.md",
    "npm-compatibility.md",
    "pages.md",
    "plugin.md",
    "realtime.md",
    "sealed.md",
    "services.md",
    "spacefast-deploy.md",
    "staged.md",
    "uploads.md",
    "wp-plugins.md",
];
/// The ones it leaves out: design proposals and records of decisions and plans (some never built),
/// and pages for hosts, for the compiler's own maintainers, or about one migration's progress. An
/// agent that searched them would answer with APIs an app does not have.
const NOT_GUIDES: &[&str] = &[
    "api-cutover-0.3.md",
    "authoring-direction.md",
    "dependencies.md",
    "emdash-coverage.md",
    "host-contract.md",
    "research-adoption.md",
    "runtime-contract.md",
    "spacefast-host-checklist.md",
    "wordpress-internals.md",
    "zero-08-parity.md",
];

/// Whether `docs/<name>` is embedded. Every page is in one list or the other, so a new page is a
/// decision and not an accident; `design-*.md` are proposals and never embedded.
fn is_guide(rel: &str) -> bool {
    let name = rel.rsplit('/').next().unwrap_or(rel);
    if GUIDES.contains(&name) {
        return true;
    }
    assert!(
        name.starts_with("design-") || NOT_GUIDES.contains(&name),
        "docs/{name} is in neither GUIDES nor NOT_GUIDES in build.rs: decide whether `typedpress mcp` should answer from it"
    );
    false
}

fn embed_docs(manifest: &Path, out_dir: &Path) {
    println!("cargo:rerun-if-changed=docs");
    println!("cargo:rerun-if-changed=docs-site/docs");
    let mut files = Vec::new();
    docs(&manifest.join("docs"), manifest, false, &["md"], &mut files);
    files.retain(|rel| is_guide(rel));
    docs(
        &manifest.join("docs-site/docs"),
        manifest,
        true,
        &["md", "mdx"],
        &mut files,
    );
    // A site page that the site's generator copies from a `docs/*.md` file is that file again;
    // one copy is enough to search.
    files.retain(|rel| {
        !std::fs::read_to_string(manifest.join(rel))
            .is_ok_and(|text| text.contains("{/* Generated by docs-site/scripts/generate.ts from docs/"))
    });
    let mut src =
        String::from("/// (path relative to the repository root, contents)\npub const DOCS: &[(&str, &str)] = &[\n");
    for f in &files {
        let abs = manifest.join(f);
        println!("cargo:rerun-if-changed={}", abs.display());
        let _ = writeln!(src, "    ({f:?}, include_str!({:?})),", abs.display().to_string());
    }
    src.push_str("];\n");
    std::fs::write(out_dir.join("docs_files.rs"), src).expect("write docs_files.rs");

    // `skills/<name>/…`: every file of every skill, as paths relative to `skills/`.
    println!("cargo:rerun-if-changed=skills");
    let root = manifest.join("skills");
    let mut files = Vec::new();
    collect(&root, &root, &mut files);
    let mut src = String::from("/// (path relative to skills/, contents)\npub const SKILLS: &[(&str, &str)] = &[\n");
    for f in &files {
        let abs = root.join(f);
        println!("cargo:rerun-if-changed={}", abs.display());
        let _ = writeln!(src, "    ({f:?}, include_str!({:?})),", abs.display().to_string());
    }
    src.push_str("];\n");
    std::fs::write(out_dir.join("skills_files.rs"), src).expect("write skills_files.rs");
}

fn main() {
    let manifest = std::env::var("CARGO_MANIFEST_DIR").expect("manifest dir");
    let root = Path::new(&manifest).join("runtime");
    println!("cargo:rerun-if-changed=runtime");
    let mut files = Vec::new();
    collect(&root, &root, &mut files);
    let mut src = String::from("/// (path relative to runtime/, contents)\npub const FILES: &[(&str, &[u8])] = &[\n");
    for f in &files {
        let abs = root.join(f);
        println!("cargo:rerun-if-changed={}", abs.display());
        let _ = writeln!(src, "    ({f:?}, include_bytes!({:?})),", abs.display().to_string());
    }
    src.push_str("];\n");
    let out_dir = std::env::var("OUT_DIR").expect("out dir");
    let out_dir = Path::new(&out_dir);
    std::fs::write(out_dir.join("runtime_files.rs"), src).expect("write runtime_files.rs");
    embed_docs(Path::new(&manifest), out_dir);
}
