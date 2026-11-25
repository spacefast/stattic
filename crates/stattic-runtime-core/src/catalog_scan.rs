//! Unified single-pass catalog scanner combining:
//! - Precompressed `.gz`/`.br` decompression-bomb enforcement
//! - Shared-apex literal cookie-write AST scanning
//! - Tiered Gitleaks + Spacefast secret scanning
//! - Publish hygiene, agent-safety, abuse, PII, and web/config guards
//!
//! Every unique `(sha256, encoding)` blob that needs inspection is opened and
//! decompressed **at most once** and fed in memory to every active scanner.
//! Known binary media/font/archive extensions are skipped before opening disk
//! files, unknown binaries stop after sniffing the first 8 KiB, unchanged
//! clean files from `previous_catalog` incur zero file opens, and live byte /
//! wall-clock budgets are enforced before opening each blob.

use serde_json::{json, Map, Value};
use std::collections::{BTreeSet, HashMap, HashSet};
use std::path::Path;
use std::time::{Duration, Instant};

use crate::catalog::{FileCatalog, ObjectIdentity};
use crate::cookie_writes::{CookieFileTarget, CookieScanSession};
use crate::decompression::{
    read_compressed_bounded, read_identity_bounded, BlobRead, CompressionEncoding,
    DecompressionReadError, DECOMPRESSION_SCAN_POLICY_DIGEST,
};
use crate::finalize::{invalid_with_details, FinalizeError, Result};
use crate::hygiene::{HygieneContext, HygieneFileKind, HygieneSession, HygieneTarget};
use crate::secret_scan::SecretScanSession;
use crate::storage::blob_path;

pub(crate) struct CatalogScanInput<'a> {
    pub blobs: &'a Path,
    pub catalog: &'a FileCatalog,
    pub previous_catalog: Option<&'a FileCatalog>,
    pub managed_apexes: &'a [String],
    pub security_config: Option<&'a Value>,
    pub exact_response_paths: &'a BTreeSet<String>,
    pub headers_exact: &'a Map<String, Value>,
    pub headers_pattern: &'a [Value],
    pub is_zero_app: bool,
}

pub(crate) struct CatalogScanOutput {
    pub cookie_scan_policy_digest: Option<String>,
    pub secret_scan_policy_digest: Option<String>,
    pub hygiene_scan_policy_digest: Option<String>,
    pub decompression_scan_policy_digest: Option<String>,
    pub cookie_scan_ms: u64,
    pub secret_scan_ms: u64,
    #[cfg_attr(not(test), allow(dead_code))]
    pub stats: CatalogScanStats,
}

#[derive(Debug, Default, Clone, Copy, PartialEq, Eq)]
pub(crate) struct CatalogScanStats {
    pub files_opened: usize,
    pub bytes_read: usize,
}

struct PendingWork<'a> {
    path: &'a str,
    logical_path: &'a str,
    sha256: &'a str,
    served_size: u64,
    cookie_target: Option<CookieFileTarget>,
    needs_secret: bool,
    hygiene_kind: Option<HygieneFileKind>,
}

fn is_web_serving_extension(logical_path: &str) -> bool {
    let Some(ext) = Path::new(logical_path)
        .extension()
        .and_then(|value| value.to_str())
    else {
        return false;
    };
    [
        "html", "htm", "xhtml", "svg", "js", "mjs", "cjs", "jsx", "ts", "tsx", "css",
    ]
    .iter()
    .any(|known| ext.eq_ignore_ascii_case(known))
}

fn is_precompressed_serving_companion(
    catalog: &FileCatalog,
    logical_path: &str,
    encoding: CompressionEncoding,
    object: &ObjectIdentity,
) -> bool {
    encoding != CompressionEncoding::Identity
        && !crate::decompression::is_known_binary_extension(logical_path)
        && (is_web_serving_extension(logical_path)
            || crate::cookie_writes::is_cookie_script_or_html(logical_path, &object.content_type)
            || catalog.paths.contains_key(logical_path))
}

struct BlobGroup<'a> {
    verify_full_stream: bool,
    enforce_bomb_limits: bool,
    items: Vec<PendingWork<'a>>,
}

/// Runs all catalog content guards in one deduplicated disk-read pass.
pub(crate) fn scan_catalog<'a>(
    input: &CatalogScanInput<'a>,
    diagnostics: &mut Vec<Value>,
) -> Result<CatalogScanOutput> {
    let mut cookie = CookieScanSession::new(input.previous_catalog, input.managed_apexes);
    let mut secret = SecretScanSession::new(input.previous_catalog, input.security_config);
    let mut hygiene = HygieneSession::new(
        &HygieneContext {
            catalog: input.catalog,
            previous_catalog: input.previous_catalog,
            exact_response_paths: input.exact_response_paths,
            headers_exact: input.headers_exact,
            headers_pattern: input.headers_pattern,
            is_zero_app: input.is_zero_app,
        },
        diagnostics,
    );

    let mut previously_validated_compressed = HashSet::<(&str, CompressionEncoding)>::new();
    if let Some(prev) = input.previous_catalog {
        if prev.decompression_scan_policy_digest.as_deref()
            == Some(DECOMPRESSION_SCAN_POLICY_DIGEST)
        {
            for (path, object) in prev.iter_objects() {
                let (logical_path, enc) = CompressionEncoding::from_path(path);
                if is_precompressed_serving_companion(prev, logical_path, enc, object) {
                    previously_validated_compressed.insert((object.sha256.as_str(), enc));
                }
            }
        }
    }
    let mut decompression_clean = true;

    // Group work by `(sha256, encoding)` so identical blobs (including source ==
    // served, or duplicate files at multiple paths) are read and decompressed
    // AT MOST ONCE across all 4 scanners.
    let mut groups: HashMap<(&str, CompressionEncoding), BlobGroup<'a>> = HashMap::new();
    let mut group_order: Vec<(&str, CompressionEncoding)> = Vec::new();

    let mut enqueue = |path: &'a str, object: &'a ObjectIdentity, is_public_served: bool| {
        let (logical_path, encoding) = CompressionEncoding::from_path(path);
        let cookie_target = cookie.target_for(logical_path, encoding, object);
        let needs_secret = secret.needs_scan(path, logical_path, object.sha256.as_str());
        let hygiene_kind = if is_public_served {
            hygiene.target_for(path, logical_path, object.sha256.as_str())
        } else {
            None
        };
        let is_serving_companion =
            is_precompressed_serving_companion(input.catalog, logical_path, encoding, object);
        let verify_full_stream = is_serving_companion
            && !previously_validated_compressed.contains(&(object.sha256.as_str(), encoding));

        if cookie_target.is_none() && !needs_secret && hygiene_kind.is_none() && !verify_full_stream
        {
            return;
        }

        let key = (object.sha256.as_str(), encoding);
        let group = groups.entry(key).or_insert_with(|| {
            group_order.push(key);
            BlobGroup {
                verify_full_stream: false,
                enforce_bomb_limits: false,
                items: Vec::new(),
            }
        });
        group.verify_full_stream |= verify_full_stream;
        group.enforce_bomb_limits |= is_serving_companion;
        group.items.push(PendingWork {
            path,
            logical_path,
            sha256: object.sha256.as_str(),
            served_size: object.size,
            cookie_target,
            needs_secret,
            hygiene_kind,
        });
    };

    for (path, entry) in &input.catalog.paths {
        match &entry.served {
            Some(served) if served.sha256 == entry.source.sha256 => {
                enqueue(path.as_str(), served, entry.public);
            }
            Some(served) => {
                enqueue(path.as_str(), &entry.source, false);
                enqueue(path.as_str(), served, entry.public);
            }
            None => {
                enqueue(path.as_str(), &entry.source, false);
            }
        }
    }
    for variant_paths in input.catalog.variants.values() {
        for (path, object) in variant_paths {
            let is_public = input
                .catalog
                .paths
                .get(path)
                .is_some_and(|entry| entry.public);
            enqueue(path.as_str(), object, is_public);
        }
    }

    let mut cookie_elapsed = Duration::ZERO;
    let mut secret_elapsed = Duration::ZERO;
    let mut stats = CatalogScanStats::default();

    for key in group_order {
        let Some(group) = groups.remove(&key) else {
            continue;
        };
        let (sha256, encoding) = key;

        // Re-check live byte and deadline budgets right before opening this blob
        // so exhausted budgets actually stop disk reads and mark inconclusive.
        let mut max_bytes = 0usize;
        let mut any_active_scanner = false;
        let mut sniff_binary_early = encoding == CompressionEncoding::Identity;
        for item in &group.items {
            if let Some(target) = item.cookie_target {
                any_active_scanner = true;
                sniff_binary_early = false;
                max_bytes = max_bytes.max(target.max_buffer_bytes());
            }
            if item.needs_secret {
                if let Some(cap) = secret.current_file_cap() {
                    any_active_scanner = true;
                    max_bytes = max_bytes.max(cap);
                } else {
                    secret.record_budget_exhausted(item.path);
                }
            }
            if item.hygiene_kind.is_some() {
                if let Some(cap) = hygiene.current_file_cap() {
                    any_active_scanner = true;
                    sniff_binary_early = false;
                    max_bytes = max_bytes.max(cap);
                }
            }
        }
        if !any_active_scanner && !group.verify_full_stream {
            continue;
        }

        let full_path = blob_path(input.blobs, sha256);
        let first_path = group.items.first().map_or("<unknown>", |item| item.path);

        stats.files_opened += 1;
        let read_result = match encoding {
            CompressionEncoding::Identity => {
                read_identity_bounded(&full_path, max_bytes, sniff_binary_early)
            }
            CompressionEncoding::Gzip | CompressionEncoding::Brotli => read_compressed_bounded(
                &full_path,
                encoding,
                max_bytes,
                group.verify_full_stream,
                group.enforce_bomb_limits,
            ),
        };

        let BlobRead { bytes, truncated } = match read_result {
            Ok(read) => {
                stats.bytes_read = stats.bytes_read.saturating_add(read.bytes.len());
                read
            }
            Err(DecompressionReadError::LimitExceeded {
                decoded_bytes,
                compressed_bytes,
                reason,
            }) => {
                return invalid_with_details(
                    "artifact_decompression_limit_exceeded",
                    format!(
                        "{first_path} exceeds precompressed artifact safety limits ({}: {decoded_bytes} decoded bytes from {compressed_bytes} compressed bytes).",
                        reason.as_str(),
                    ),
                    json!({
                        "path": first_path,
                        "sha256": sha256,
                        "decodedBytes": decoded_bytes,
                        "compressedBytes": compressed_bytes,
                        "reason": reason.as_str(),
                        "pointer": "/files",
                    }),
                );
            }
            Err(DecompressionReadError::Io(error)) => {
                if encoding == CompressionEncoding::Identity {
                    return Err(FinalizeError::Io {
                        path: full_path,
                        source: error,
                    });
                }
                if let Some(cookie_item) = group.items.iter().find(|i| i.cookie_target.is_some()) {
                    return Err(FinalizeError::Invalid {
                        code: "artifact_cookie_scan_failed",
                        message: format!(
                            "Could not inspect script text in {}: {error}",
                            cookie_item.path
                        ),
                        details: Some(json!({"path": cookie_item.path, "pointer": "/files"})),
                    });
                }
                decompression_clean = false;
                secret.mark_inconclusive(first_path);
                if group.items.iter().any(|i| i.needs_secret) && max_bytes > 0 {
                    let raw = read_identity_bounded(&full_path, max_bytes, true).map_err(
                        |err| match err {
                            DecompressionReadError::Io(source) => FinalizeError::Io {
                                path: full_path.clone(),
                                source,
                            },
                            DecompressionReadError::LimitExceeded { .. } => {
                                FinalizeError::Invalid {
                                    code: "artifact_decompression_limit_exceeded",
                                    message: format!(
                                        "{first_path} exceeds artifact safety limits."
                                    ),
                                    details: None,
                                }
                            }
                        },
                    )?;
                    stats.bytes_read = stats.bytes_read.saturating_add(raw.bytes.len());
                    raw
                } else {
                    continue;
                }
            }
        };

        for item in group.items {
            if let Some(target) = item.cookie_target {
                let started = Instant::now();
                cookie.scan_bytes(target, item.path, item.sha256, &bytes, truncated)?;
                cookie_elapsed += started.elapsed();
            }
            if item.needs_secret {
                let started = Instant::now();
                secret.scan_bytes(
                    item.path,
                    item.logical_path,
                    item.sha256,
                    &bytes,
                    truncated,
                    diagnostics,
                )?;
                secret_elapsed += started.elapsed();
            }
            if let Some(kind) = item.hygiene_kind {
                hygiene.scan_content_bytes(
                    HygieneTarget {
                        kind,
                        path: item.path,
                        logical_path: item.logical_path,
                        served_size: item.served_size,
                    },
                    &bytes,
                    truncated,
                    diagnostics,
                )?;
            }
        }
    }

    Ok(CatalogScanOutput {
        cookie_scan_policy_digest: cookie.finish(),
        secret_scan_policy_digest: secret.finish(diagnostics),
        hygiene_scan_policy_digest: hygiene.finish(),
        decompression_scan_policy_digest: decompression_clean
            .then(|| DECOMPRESSION_SCAN_POLICY_DIGEST.to_string()),
        cookie_scan_ms: cookie_elapsed.as_millis().min(u64::MAX as u128) as u64,
        secret_scan_ms: secret_elapsed.as_millis().min(u64::MAX as u128) as u64,
        stats,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::catalog::{CatalogPath, FILE_CATALOG_FORMAT};
    use flate2::{write::GzEncoder, Compression};
    use sha2::{Digest, Sha256};
    use std::collections::BTreeMap;
    use std::io::Write;

    fn make_entry(blobs: &Path, bytes: &[u8], content_type: &str) -> CatalogPath {
        let sha = format!("{:x}", Sha256::digest(bytes));
        let target = blob_path(blobs, &sha);
        if let Some(parent) = target.parent() {
            std::fs::create_dir_all(parent).unwrap();
        }
        std::fs::write(&target, bytes).unwrap();
        let identity = ObjectIdentity {
            sha256: sha,
            size: bytes.len() as u64,
            content_type: content_type.to_string(),
        };
        CatalogPath {
            source: identity.clone(),
            served: Some(identity),
            public: true,
        }
    }

    #[test]
    fn scans_each_text_blob_once_skips_binary_assets_and_reuses_clean_cache() {
        let temp = tempfile::tempdir().unwrap();
        let blobs = temp.path().join("blobs");

        let html = b"<!doctype html><html><body><h1>Hello</h1><script src=\"/app.js\"></script></body></html>";
        let js = b"export const version = 1;\n";
        let png = b"\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDRfake-png-bytes";
        let mut gz = GzEncoder::new(Vec::new(), Compression::default());
        gz.write_all(html).unwrap();
        let html_gz = gz.finish().unwrap();

        let mut paths = BTreeMap::new();
        paths.insert("index.html".into(), make_entry(&blobs, html, "text/html"));
        paths.insert(
            "index.html.gz".into(),
            make_entry(&blobs, &html_gz, "text/html"),
        );
        paths.insert(
            "app.js".into(),
            make_entry(&blobs, js, "application/javascript"),
        );
        paths.insert("logo.png".into(), make_entry(&blobs, png, "image/png"));

        let mut catalog = FileCatalog {
            format: FILE_CATALOG_FORMAT.into(),
            space_id: "spc_perf".into(),
            version_id: "ver_1".into(),
            paths,
            variants: BTreeMap::new(),
            serving_digest: "d1".into(),
            template_paths: Vec::new(),
            generated_at: "2026-11-24T00:00:00Z".into(),
            pipeline_context_digest: None,
            cookie_scan_policy_digest: None,
            secret_scan_policy_digest: None,
            hygiene_scan_policy_digest: None,
            decompression_scan_policy_digest: None,
        };

        let apexes = vec!["space.fast".to_string()];
        let exact_paths = BTreeSet::new();
        let headers_exact = Map::new();
        let mut diagnostics = Vec::new();

        // First publish: 3 text/compressed files opened ONCE each; logo.png opened 0 times.
        let first = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &catalog,
                previous_catalog: None,
                managed_apexes: &apexes,
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .unwrap();
        assert!(diagnostics.is_empty(), "{diagnostics:?}");
        assert_eq!(first.stats.files_opened, 3);
        assert!(first.cookie_scan_policy_digest.is_some());
        assert!(first.secret_scan_policy_digest.is_some());
        assert!(first.hygiene_scan_policy_digest.is_some());
        assert!(first.decompression_scan_policy_digest.is_some());

        catalog.cookie_scan_policy_digest = first.cookie_scan_policy_digest;
        catalog.secret_scan_policy_digest = first.secret_scan_policy_digest;
        catalog.hygiene_scan_policy_digest = first.hygiene_scan_policy_digest;
        catalog.decompression_scan_policy_digest = first.decompression_scan_policy_digest;

        // Clean incremental republish with no file changes: ZERO file opens!
        let second = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &catalog,
                previous_catalog: Some(&catalog),
                managed_apexes: &apexes,
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .unwrap();
        assert_eq!(second.stats.files_opened, 0);
        assert_eq!(second.stats.bytes_read, 0);

        // Channel variant with a malicious script must be blocked by hygiene guards.
        let mut variant_catalog = catalog.clone();
        let bad_variant = make_entry(
            &blobs,
            b"<script src=\"https://cdn.polyfill.io/v3/polyfill.min.js\"></script>",
            "text/html",
        );
        variant_catalog.variants.insert(
            "preview".into(),
            BTreeMap::from([("index.html".into(), bad_variant.source)]),
        );
        let variant_err = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &variant_catalog,
                previous_catalog: Some(&catalog),
                managed_apexes: &apexes,
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .err()
        .expect("channel variant must run blocking hygiene guards");
        match variant_err {
            FinalizeError::Invalid { code, .. } => assert_eq!(code, "malicious_script_reference"),
            other => panic!("unexpected error: {other:?}"),
        }
    }

    #[test]
    fn scans_large_html_beyond_4mib_for_shared_apex_cookie_writes() {
        let temp = tempfile::tempdir().unwrap();
        let blobs = temp.path().join("blobs");

        let mut large_html = Vec::with_capacity(4_500_000);
        large_html.extend_from_slice(b"<!doctype html><html><body>");
        large_html.resize(4_400_000, b' ');
        large_html.extend_from_slice(
            b"<script>document.cookie = 's=1; domain=space.fast';</script></body></html>",
        );

        let mut paths = BTreeMap::new();
        paths.insert(
            "large.html".into(),
            make_entry(&blobs, &large_html, "text/html"),
        );

        let catalog = FileCatalog {
            format: FILE_CATALOG_FORMAT.into(),
            space_id: "spc_cookie_large".into(),
            version_id: "ver_1".into(),
            paths,
            variants: BTreeMap::new(),
            serving_digest: "d1".into(),
            template_paths: Vec::new(),
            generated_at: "2026-11-24T00:00:00Z".into(),
            pipeline_context_digest: None,
            cookie_scan_policy_digest: None,
            secret_scan_policy_digest: None,
            hygiene_scan_policy_digest: None,
            decompression_scan_policy_digest: None,
        };
        let apexes = vec!["space.fast".to_string()];
        let exact_paths = BTreeSet::new();
        let headers_exact = Map::new();
        let mut diagnostics = Vec::new();

        let err = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &catalog,
                previous_catalog: None,
                managed_apexes: &apexes,
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .err()
        .expect("large HTML cookie write beyond 4 MiB must be refused");
        match err {
            FinalizeError::Invalid { code, .. } => assert_eq!(code, "shared_apex_cookie_write"),
            other => panic!("unexpected error: {other:?}"),
        }
    }

    #[test]
    fn skips_downloadable_archives_and_high_ratio_standalone_gz_while_blocking_companion_bombs() {
        let temp = tempfile::tempdir().unwrap();
        let blobs = temp.path().join("blobs");

        let mut gz = GzEncoder::new(Vec::new(), Compression::best());
        gz.write_all(&vec![b'0'; 2 * 1024 * 1024]).unwrap();
        let high_ratio_gz = gz.finish().unwrap();

        // 1) Standalone downloadable archives (`release.tar.gz`, `dataset.csv.gz`) must not block.
        let mut paths = BTreeMap::new();
        paths.insert(
            "release.tar.gz".into(),
            make_entry(&blobs, &high_ratio_gz, "application/gzip"),
        );
        paths.insert(
            "dataset.csv.gz".into(),
            make_entry(&blobs, &high_ratio_gz, "text/csv"),
        );
        let standalone_catalog = FileCatalog {
            format: FILE_CATALOG_FORMAT.into(),
            space_id: "spc_archives".into(),
            version_id: "ver_1".into(),
            paths,
            variants: BTreeMap::new(),
            serving_digest: "d1".into(),
            template_paths: Vec::new(),
            generated_at: "2026-11-24T00:00:00Z".into(),
            pipeline_context_digest: None,
            cookie_scan_policy_digest: None,
            secret_scan_policy_digest: None,
            hygiene_scan_policy_digest: None,
            decompression_scan_policy_digest: None,
        };
        let exact_paths = BTreeSet::new();
        let headers_exact = Map::new();
        let mut diagnostics = Vec::new();
        let ok = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &standalone_catalog,
                previous_catalog: None,
                managed_apexes: &[],
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .expect("standalone downloadable .tar.gz and .csv.gz must not be blocked as bombs");
        // release.tar.gz is skipped entirely; only dataset.csv.gz is opened once for bounded text scan.
        assert_eq!(ok.stats.files_opened, 1);

        // 2) Serving companion (`dataset.csv` + `dataset.csv.gz`) IS enforced as a decompression
        //    bomb even incrementally when v1 carried the same bytes as a standalone download.
        let mut prev_standalone = standalone_catalog.clone();
        prev_standalone.decompression_scan_policy_digest = ok.decompression_scan_policy_digest;
        let mut companion_catalog = standalone_catalog;
        companion_catalog.paths.insert(
            "dataset.csv".into(),
            make_entry(&blobs, b"a,b\n1,2\n", "text/csv"),
        );
        let err = scan_catalog(
            &CatalogScanInput {
                blobs: &blobs,
                catalog: &companion_catalog,
                previous_catalog: Some(&prev_standalone),
                managed_apexes: &[],
                security_config: None,
                exact_response_paths: &exact_paths,
                headers_exact: &headers_exact,
                headers_pattern: &[],
                is_zero_app: false,
            },
            &mut diagnostics,
        )
        .err()
        .expect("precompressed serving companion bomb must be refused even after standalone v1");
        match err {
            FinalizeError::Invalid { code, .. } => {
                assert_eq!(code, "artifact_decompression_limit_exceeded");
            }
            other => panic!("unexpected error: {other:?}"),
        }
    }
}
