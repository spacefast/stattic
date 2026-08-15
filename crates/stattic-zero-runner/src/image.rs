//! Server-rendered images for Zero endpoints.
//!
//! An endpoint result carrying `image` hands the runner a takumi node tree
//! instead of a body; this module turns that tree into PNG bytes and keeps a
//! content-addressed cache on disk so an unchanged image is rendered once per
//! site, not once per request.

use std::fs::{self, File, FileTimes, OpenOptions};
use std::io::Write;
use std::path::{Path, PathBuf};
use std::sync::OnceLock;
use std::time::SystemTime;

use serde::Deserialize;
use serde_json::Value;
use sha2::{Digest, Sha256};
use takumi::prelude::{
    FontResource, Fonts, GenericFamily, Node, OutputFormat, RenderOptions, Viewport,
};
use takumi::{render, write_image};

use crate::constants::RENDERER_ABI;

const DEFAULT_WIDTH: u32 = 1200;
const DEFAULT_HEIGHT: u32 = 630;
const MIN_DIMENSION: u32 = 16;
const MAX_DIMENSION: u32 = 2048;

const CACHE_DIR_NAME: &str = "zero-render-cache";
/// Entry count that triggers eviction, and the count eviction trims back to.
/// The gap keeps eviction amortized instead of running on every write.
const CACHE_MAX_ENTRIES: usize = 256;
const CACHE_TARGET_ENTRIES: usize = 192;

/// The `image` member of a Zero endpoint result: a takumi node tree plus the
/// viewport to render it in.
#[derive(Debug, Deserialize)]
pub(crate) struct ImageRenderSpec {
    #[serde(default)]
    pub node: Value,
    #[serde(default)]
    pub width: Option<u32>,
    #[serde(default)]
    pub height: Option<u32>,
}

/// Renders `spec` to PNG bytes, reusing the site's render cache when the same
/// tree and viewport were rendered before.
///
/// `version_root` locates the cache: it lives beside the version directory, so
/// it survives version deploys the way the `functions/` artifacts do. Cache
/// failures are never request failures — every I/O error falls through to a
/// fresh render.
pub(crate) fn render_png(spec: &ImageRenderSpec, version_root: &str) -> Result<Vec<u8>, String> {
    let width = spec.width.unwrap_or(DEFAULT_WIDTH);
    let height = spec.height.unwrap_or(DEFAULT_HEIGHT);
    for (name, value) in [("width", width), ("height", height)] {
        if !(MIN_DIMENSION..=MAX_DIMENSION).contains(&value) {
            return Err(format!(
                "Zero image {name} must be in {MIN_DIMENSION}..={MAX_DIMENSION}, got {value}."
            ));
        }
    }

    let cache_path = cache_path_for(version_root, spec, width, height);
    if let Some(cached) = cache_path.as_deref().and_then(read_cache_entry) {
        return Ok(cached);
    }

    let png = render_fresh(&spec.node, width, height)?;
    if let Some(cache_path) = cache_path.as_deref() {
        write_cache_entry(cache_path, &png);
    }
    Ok(png)
}

/// The strong validator for a rendered PNG: the first 32 hex characters of its
/// SHA-256, quoted per RFC 9110.
pub(crate) fn etag_for(png: &[u8]) -> String {
    let digest = format!("{:x}", Sha256::digest(png));
    format!("\"{}\"", &digest[..32])
}

fn render_fresh(node: &Value, width: u32, height: u32) -> Result<Vec<u8>, String> {
    let node: Node = serde_json::from_value(node.clone())
        .map_err(|error| format!("Invalid image node: {error}"))?;
    let options = RenderOptions::builder()
        .viewport(Viewport::new((width, height)))
        .node(node)
        .fonts(fonts())
        .build();
    let bitmap = render(options).map_err(|error| format!("Image render failed: {error}"))?;
    let mut png = Vec::new();
    write_image(&bitmap, &mut png, OutputFormat::Png)
        .map_err(|error| format!("Image encode failed: {error}"))?;
    Ok(png)
}

/// The process-wide font context. Building it assembles a font collection, so
/// it is built once and shared by reference across every render.
///
/// A face has to be registered here or text draws nothing: `Fonts::default()`
/// registers no families and disables system font discovery, and takumi ships
/// no font of its own.
///
/// These are the platform's own faces, so a capsule's picture looks like the
/// page it belongs to without the author choosing anything. Each is bound to
/// the CSS generic it answers — an unstyled text node computes
/// `font-family: sans-serif`, which is what makes Hasköy the default rather
/// than a name every author would have to type.
///
/// Recoleta, the display face, is deliberately absent: it is commercial and
/// never enters this repo (`packages/app-shell/src/styles/shared/fonts.css`
/// loads it from a CDN for that reason), and embedding it in a shipped binary
/// is redistribution. Headings fall back to Hasköy. Both faces here are OFL,
/// whose terms are met by shipping the license text beside them.
fn fonts() -> &'static Fonts {
    static FONTS: OnceLock<Fonts> = OnceLock::new();
    FONTS.get_or_init(|| {
        let mut fonts = Fonts::default();
        for (face, generic) in [
            (
                include_bytes!("../assets/fonts/haskoy-latin-variable.woff2").as_slice(),
                GenericFamily::SANS_SERIF,
            ),
            (
                include_bytes!("../assets/fonts/jbm-regular.ttf").as_slice(),
                GenericFamily::MONOSPACE,
            ),
        ] {
            fonts
                .register(FontResource::new(face).generic_family(generic))
                .expect("an embedded platform face must load");
        }
        fonts
    })
}

fn cache_path_for(
    version_root: &str,
    spec: &ImageRenderSpec,
    width: u32,
    height: u32,
) -> Option<PathBuf> {
    let site_root = Path::new(version_root).parent()?;
    let key = cache_key(&spec.node, width, height);
    let shard = key.get(..2)?;
    Some(
        site_root
            .join(CACHE_DIR_NAME)
            .join(shard)
            .join(format!("{key}.png")),
    )
}

/// The content address of a render: the renderer version, the viewport, and a
/// key-order-independent serialization of the node tree.
fn cache_key(node: &Value, width: u32, height: u32) -> String {
    let mut hasher = Sha256::new();
    hasher.update(RENDERER_ABI.as_bytes());
    hasher.update([0]);
    hasher.update(width.to_be_bytes());
    hasher.update(height.to_be_bytes());
    hasher.update([0]);
    hasher.update(serde_json::to_vec(&canonicalize(node)).unwrap_or_default());
    format!("{:x}", hasher.finalize())
}

/// Rebuilds `value` with every object's keys in sorted order, so two trees that
/// differ only in JSON key order hash the same.
fn canonicalize(value: &Value) -> Value {
    match value {
        Value::Object(map) => {
            let mut keys: Vec<&String> = map.keys().collect();
            keys.sort_unstable();
            let mut sorted = serde_json::Map::with_capacity(keys.len());
            for key in keys {
                let Some(child) = map.get(key) else { continue };
                sorted.insert(key.clone(), canonicalize(child));
            }
            Value::Object(sorted)
        }
        Value::Array(items) => Value::Array(items.iter().map(canonicalize).collect()),
        other => other.clone(),
    }
}

const PNG_MAGIC: [u8; 8] = [137, 80, 78, 71, 13, 10, 26, 10];

fn read_cache_entry(path: &Path) -> Option<Vec<u8>> {
    let bytes = fs::read(path).ok()?;
    // A cache entry is addressed by its inputs, so a damaged file would
    // otherwise be served for that input forever. Re-render instead.
    if !bytes.starts_with(&PNG_MAGIC) {
        return None;
    }
    // Eviction is oldest-mtime-first, so a hit has to look recently used.
    if let Ok(file) = OpenOptions::new().write(true).open(path) {
        let _ = file.set_times(FileTimes::new().set_modified(SystemTime::now()));
    }
    Some(bytes)
}

fn write_cache_entry(path: &Path, png: &[u8]) {
    let Some(shard) = path.parent() else { return };
    if fs::create_dir_all(shard).is_err() {
        return;
    }
    // Rename from a same-directory temp file so a concurrent reader never sees
    // a half-written PNG. Racing writers produce identical bytes by
    // construction, so whichever rename lands last is still correct.
    let temp_path = shard.join(format!(
        "{}.{}.tmp",
        path.file_stem().unwrap_or_default().to_string_lossy(),
        std::process::id()
    ));
    let written = File::create(&temp_path).and_then(|mut file| {
        file.write_all(png)?;
        file.sync_all()
    });
    if written.is_err() || fs::rename(&temp_path, path).is_err() {
        let _ = fs::remove_file(&temp_path);
        return;
    }
    evict_cache_entries(shard.parent().unwrap_or(shard));
}

/// Trims the cache back to [`CACHE_TARGET_ENTRIES`] once it grows past
/// [`CACHE_MAX_ENTRIES`], oldest modification time first. Every step is
/// best-effort: a concurrent writer removing an entry first is not an error.
fn evict_cache_entries(cache_root: &Path) {
    let mut entries: Vec<(SystemTime, PathBuf)> = Vec::new();
    let Ok(shards) = fs::read_dir(cache_root) else {
        return;
    };
    for shard in shards.flatten() {
        let Ok(files) = fs::read_dir(shard.path()) else {
            continue;
        };
        for file in files.flatten() {
            if file
                .path()
                .extension()
                .is_none_or(|extension| extension != "png")
            {
                continue;
            }
            let modified = file
                .metadata()
                .and_then(|metadata| metadata.modified())
                .unwrap_or(SystemTime::UNIX_EPOCH);
            entries.push((modified, file.path()));
        }
    }
    if entries.len() <= CACHE_MAX_ENTRIES {
        return;
    }
    entries.sort_unstable_by_key(|(modified, _)| *modified);
    for (_, path) in entries.iter().take(entries.len() - CACHE_TARGET_ENTRIES) {
        let _ = fs::remove_file(path);
    }
}

#[cfg(test)]
mod tests {
    use std::time::Duration;

    use serde_json::json;

    use super::*;

    /// The embedded faces are what make text visible: with none registered,
    /// takumi lays glyphs out and draws nothing, so a card and the same card
    /// without its headline rasterize to identical bytes. Each face also has to
    /// answer the generic it was bound to — an author writing `monospace` gets
    /// the platform's mono voice, and one writing nothing gets its sans.
    #[test]
    fn platform_faces_answer_the_generic_families() {
        let uncached = tempfile::tempdir().expect("tempdir");
        let render = |node: Value| {
            render_png(
                &ImageRenderSpec {
                    node,
                    width: Some(160),
                    height: Some(48),
                },
                &uncached.path().join("current").to_string_lossy(),
            )
            .expect("render")
        };
        let card = |style: Value| {
            render(json!({
                "type": "container",
                "children": [{ "type": "text", "text": "Spacefast", "style": style }],
            }))
        };

        let blank = render(json!({ "type": "container" }));
        let default_face = card(json!({ "fontSize": 24 }));
        let mono = card(json!({ "fontSize": 24, "fontFamily": "monospace" }));

        assert_ne!(blank, default_face, "text must rasterize, not draw nothing");
        assert_ne!(blank, mono, "the monospace generic must resolve to a face");
        // Same string at the same size in two families: identical bytes would
        // mean one generic fell through to the other's face.
        assert_ne!(default_face, mono, "each generic must reach its own face");
    }

    /// The cache key carries [`RENDERER_ABI`], so a takumi upgrade that leaves
    /// the constant behind would serve the old renderer's pixels forever.
    #[test]
    fn renderer_abi_names_the_linked_takumi_version() {
        let lock =
            fs::read_to_string(Path::new(env!("CARGO_MANIFEST_DIR")).join("../../Cargo.lock"))
                .expect("workspace lockfile");
        let resolved = lock
            .split("[[package]]")
            .filter(|package| package.contains("name = \"takumi\"\n"))
            .find_map(|package| {
                package
                    .lines()
                    .find_map(|line| line.strip_prefix("version = "))
            })
            .expect("takumi in the lockfile")
            .trim_matches('"');

        assert_eq!(RENDERER_ABI, format!("takumi-{resolved}"));
    }

    #[test]
    fn eviction_trims_an_overfull_cache_to_the_newest_entries() {
        let cache = tempfile::tempdir().expect("tempdir");
        let now = SystemTime::now();
        let entry_path = |index: usize| {
            cache
                .path()
                .join(format!("{:02x}", index % 256))
                .join(format!("entry-{index}.png"))
        };
        // Age ascends with the index, so the survivors are the highest indexes.
        for index in 0..CACHE_MAX_ENTRIES + 4 {
            let path = entry_path(index);
            fs::create_dir_all(path.parent().expect("shard")).expect("shard dir");
            let file = File::create(&path).expect("entry");
            let modified = now - Duration::from_secs((CACHE_MAX_ENTRIES + 4 - index) as u64);
            file.set_times(FileTimes::new().set_modified(modified))
                .expect("mtime");
        }

        evict_cache_entries(cache.path());

        let survivors: Vec<usize> = (0..CACHE_MAX_ENTRIES + 4)
            .filter(|index| entry_path(*index).exists())
            .collect();
        assert_eq!(survivors.len(), CACHE_TARGET_ENTRIES);
        assert_eq!(
            survivors[0],
            CACHE_MAX_ENTRIES + 4 - CACHE_TARGET_ENTRIES,
            "oldest entries must be the ones dropped"
        );
    }
}
