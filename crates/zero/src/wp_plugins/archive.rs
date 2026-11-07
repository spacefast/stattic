use super::{Plugin, Result, digest, invalid};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::io::{Read, Write};
use std::path::{Path, PathBuf};

const MAX_FILES: usize = 20_000;
const MAX_EXPANDED: u64 = 256 * 1024 * 1024;

/// ZIP decoding and CRC checks belong to the zip crate; never shell out or execute PHP.
/// Like Playground installAsset: stage, unwrap one root directory, then install by identity.
pub(super) fn extract(zip: &Path, dest: &Path, plugin: &Plugin) -> Result<()> {
    let mut archive = zip::ZipArchive::new(std::fs::File::open(zip)?)
        .map_err(|e| invalid(format!("plugin {}: invalid ZIP: {e}", plugin.slug)))?;
    // ZipArchive indexes unique names; consult the raw-directory duplicate flag first.
    if archive.has_duplicate_names() {
        return Err(invalid("duplicate archive paths in central directory"));
    }
    if archive.len() > MAX_FILES {
        return Err(invalid("plugin archive has too many entries"));
    }
    archive
        .validate_header_consistency()
        .map_err(|e| invalid(format!("plugin {}: {e}", plugin.slug)))?;
    let mut paths = Vec::new();
    let mut seen = BTreeSet::new();
    let mut spelling = BTreeMap::new();
    let mut total = 0u64;
    for i in 0..archive.len() {
        let f = archive.by_index(i)?;
        let name = f.name()?;
        let clean = name.trim_end_matches('/');
        if clean.is_empty()
            || name.contains('\\')
            || name.contains(':')
            || name.chars().any(char::is_control)
            || clean.split('/').any(|c| c.is_empty() || c == "." || c == "..")
            || f.enclosed_name().is_none()
        {
            return Err(invalid(format!("plugin {}: unsafe archive path `{name}`", plugin.slug)));
        }
        let mode = f.unix_mode().unwrap_or(0) & 0o170000;
        if mode != 0 && mode != 0o100000 && mode != 0o040000 {
            return Err(invalid(format!(
                "plugin {}: archive contains a symlink or special file",
                plugin.slug
            )));
        }
        if !seen.insert(clean.to_ascii_lowercase()) {
            return Err(invalid(format!(
                "plugin {}: duplicate/case-colliding archive path `{name}`",
                plugin.slug
            )));
        }
        let mut prefix = String::new();
        for part in clean.split('/') {
            if !prefix.is_empty() {
                prefix.push('/');
            }
            prefix.push_str(part);
            if let Some(existing) = spelling.insert(prefix.to_ascii_lowercase(), prefix.clone())
                && existing != prefix
            {
                return Err(invalid("case-colliding archive directory"));
            }
        }
        total = total
            .checked_add(f.size())
            .ok_or_else(|| invalid("ZIP size overflow"))?;
        if total > MAX_EXPANDED {
            return Err(invalid("plugin archive exceeds 256 MiB expanded"));
        }
        if clean.split('/').next() == Some("__MACOSX") {
            continue;
        }
        paths.push((i, clean.to_string(), f.is_dir()));
    }
    let roots: BTreeSet<_> = paths.iter().map(|(_, p, _)| p.split('/').next().unwrap()).collect();
    let root = if roots.len() == 1 && paths.iter().all(|(_, p, dir)| p.contains('/') || *dir) {
        roots.first().copied()
    } else {
        None
    };
    // Explicit identity is the destination, as with installPlugin targetFolderName.
    let mapped: Vec<_> = paths
        .iter()
        .filter_map(|(i, name, dir)| {
            let rel = if let Some(root) = root {
                name.strip_prefix(root).unwrap().trim_start_matches('/')
            } else {
                name
            };
            (!rel.is_empty()).then(|| (*i, rel.to_string(), *dir))
        })
        .collect();
    let kinds: BTreeMap<_, _> = mapped
        .iter()
        .map(|(_, p, dir)| (p.to_ascii_lowercase(), *dir))
        .collect();
    for (_, path, _) in &mapped {
        let mut parent = Path::new(path).parent();
        while let Some(p) = parent {
            if kinds.get(&p.to_string_lossy().to_ascii_lowercase()) == Some(&false) {
                return Err(invalid("archive file/directory collision"));
            }
            parent = p.parent();
        }
    }
    std::fs::create_dir_all(dest)?;
    for (i, path, dir) in mapped {
        let to = dest.join(path);
        if dir {
            std::fs::create_dir_all(to)?;
            continue;
        }
        std::fs::create_dir_all(to.parent().unwrap())?;
        let mut f = archive.by_index(i)?;
        let mut out = std::fs::OpenOptions::new().write(true).create_new(true).open(to)?;
        // Read to EOF to verify CRC, while bounding actual decompression independently of ZIP metadata.
        let n = std::io::copy(&mut f.by_ref().take(MAX_EXPANDED + 1), &mut out)?;
        if n != f.size() {
            return Err(invalid("archive decompressed size mismatch"));
        }
        out.flush()?;
    }
    validate_headers(dest, plugin)
}

fn header(text: &str, key: &str) -> Option<String> {
    // Match WordPress get_file_data(), including PHPDoc @ prefixes and CR line endings.
    let text = text.replace('\r', "\n");
    let pattern = regex::Regex::new(&format!(
        r"(?im)^(?:[ \t]*<\?php)?[ \t/*#@]*{}:(.*)$",
        regex::escape(key)
    ))
    .expect("escaped header pattern");
    let captures = pattern.captures(&text)?;
    let value = captures.get(1)?.as_str().split("*/").next()?.split("?>").next()?;
    Some(value.trim().to_string())
}

fn validate_headers(dir: &Path, plugin: &Plugin) -> Result<()> {
    let mut main = Vec::new();
    for entry in std::fs::read_dir(dir)? {
        let path = entry?.path();
        if !path.is_file() || path.extension().is_none_or(|e| e != "php") {
            continue;
        }
        let mut bytes = Vec::new();
        std::fs::File::open(&path)?.take(8192).read_to_end(&mut bytes)?;
        let text = String::from_utf8_lossy(&bytes);
        if header(&text, "Plugin Name").is_some_and(|s| !s.is_empty()) {
            main.push(text.into_owned());
        }
    }
    if main.len() != 1 {
        return Err(invalid(format!(
            "plugin {}: expected one root PHP Plugin Name header, found {}",
            plugin.slug,
            main.len()
        )));
    }
    let text = &main[0];
    if !header(text, "Version").is_some_and(|v| super::version::header_matches(&v, &plugin.version)) {
        return Err(invalid(format!(
            "plugin {}: archive Version does not match {}",
            plugin.slug, plugin.version
        )));
    }
    if let Some(deps) = header(text, "Requires Plugins") {
        for dep in deps.split(',').map(str::trim).filter(|s| !s.is_empty()) {
            if !plugin.requires.iter().any(|d| d == dep) {
                return Err(invalid(format!(
                    "plugin {}: header dependency `{dep}` must be declared in requires",
                    plugin.slug
                )));
            }
        }
    }
    Ok(())
}

/// Stable path/content digest also detects local changes before replacing a managed plugin.
pub(super) fn tree_digest(root: &Path) -> Result<String> {
    fn walk(root: &Path, rel: &Path, hash: &mut Sha256) -> Result<()> {
        let mut entries: Vec<_> = std::fs::read_dir(root.join(rel))?.collect::<std::io::Result<_>>()?;
        entries.sort_by_key(|e| e.file_name());
        for entry in entries {
            let meta = entry.file_type()?;
            let child = rel.join(entry.file_name());
            let name = child.to_str().ok_or_else(|| invalid("non-UTF8 plugin path"))?;
            hash.update((name.len() as u64).to_le_bytes());
            hash.update(name.as_bytes());
            if meta.is_dir() {
                hash.update(b"d");
                walk(root, &child, hash)?;
            } else if meta.is_file() {
                hash.update(b"f");
                hash.update(digest(&std::fs::read(entry.path())?).as_bytes());
            } else {
                return Err(invalid("plugin tree contains symlink or special file"));
            }
        }
        Ok(())
    }
    let mut hash = Sha256::new();
    walk(root, Path::new(""), &mut hash)?;
    Ok(super::hex(&hash.finalize()))
}

pub(super) fn copy_tree(from: &Path, to: &Path) -> Result<()> {
    std::fs::create_dir_all(to)?;
    for entry in std::fs::read_dir(from)? {
        let entry = entry?;
        let kind = entry.file_type()?;
        let target: PathBuf = to.join(entry.file_name());
        if kind.is_dir() {
            copy_tree(&entry.path(), &target)?;
        } else if kind.is_file() {
            std::fs::copy(entry.path(), target)?;
        } else {
            return Err(invalid("assembly input contains symlink or special file"));
        }
    }
    Ok(())
}
