//! Embeds the runtime into the binary: every file under `runtime/` (PHP runtime,
//! WordPress stores, plugin template, client runtime). New files are picked up
//! without code changes; `zero build` decides which go where.

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
    let out = Path::new(&std::env::var("OUT_DIR").expect("out dir")).join("runtime_files.rs");
    std::fs::write(out, src).expect("write runtime_files.rs");
}
