//! Uniform bounded decompression guard for precompressed `.gz` and `.br`
//! artifacts in the CAS.
//!
//! Protects both the serving pipeline and every content scanner against
//! decompression bombs by enforcing an absolute decoded byte ceiling and a
//! maximum expansion ratio once decoded output passes a small floor.

use std::fs::File;
use std::io::{self, Read};
use std::path::Path;

/// Hard ceiling on decoded bytes for any precompressed companion artifact.
pub(crate) const MAX_DECOMPRESSED_BYTES: u64 = 64 * 1024 * 1024;

/// Policy marker stored on `FileCatalog` only when every precompressed serving
/// companion in the version completed bounded decompression verification.
pub(crate) const DECOMPRESSION_SCAN_POLICY_DIGEST: &str = "decompression-guard:v2";

/// Once decoded bytes pass this threshold, the expansion ratio check activates
/// so tiny highly-compressible HTML stubs never trigger false positives.
const RATIO_CHECK_MIN_DECODED_BYTES: u64 = 1024 * 1024;

/// Maximum allowed `decoded / compressed` expansion ratio above the floor.
const MAX_DECOMPRESSION_RATIO: u64 = 100;

/// Prefix size inspected for NUL bytes when sniffing unknown uncompressed files.
const BINARY_SNIFF_BYTES: usize = 8192;

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash)]
pub(crate) enum CompressionEncoding {
    Identity,
    Gzip,
    Brotli,
}

impl CompressionEncoding {
    pub(crate) fn from_path(path: &str) -> (&str, Self) {
        let Some(source) = crate::serving_paths::precompressed_source(path) else {
            return (path, Self::Identity);
        };
        if path.len() >= 3 && path[path.len() - 3..].eq_ignore_ascii_case(".gz") {
            (source, Self::Gzip)
        } else {
            (source, Self::Brotli)
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum DecompressionLimitReason {
    SizeExceeded,
    RatioExceeded,
}

impl DecompressionLimitReason {
    #[must_use]
    pub(crate) const fn as_str(self) -> &'static str {
        match self {
            Self::SizeExceeded => "decompressed_size_exceeded",
            Self::RatioExceeded => "decompression_ratio_exceeded",
        }
    }
}

#[derive(Debug)]
pub(crate) enum DecompressionReadError {
    Io(io::Error),
    LimitExceeded {
        decoded_bytes: u64,
        compressed_bytes: u64,
        reason: DecompressionLimitReason,
    },
}

/// Result of one bounded blob read.
pub(crate) struct BlobRead {
    pub bytes: Vec<u8>,
    pub truncated: bool,
}

/// Returns `true` when `logical_path` has a well-known binary media, font,
/// archive, or compiled binary extension that never contains text scripts,
/// HTML, or plaintext secrets, allowing scanners to skip opening uncompressed
/// blobs from disk entirely.
#[must_use]
pub(crate) fn is_known_binary_extension(logical_path: &str) -> bool {
    let Some(ext) = Path::new(logical_path)
        .extension()
        .and_then(|value| value.to_str())
    else {
        return false;
    };
    const BINARY_EXTS: &[&str] = &[
        "png", "jpg", "jpeg", "gif", "webp", "avif", "ico", "bmp", "tiff", "tif", "woff", "woff2",
        "ttf", "otf", "eot", "mp4", "webm", "mov", "avi", "mp3", "wav", "ogg", "flac", "m4a",
        "pdf", "zip", "tar", "7z", "wasm", "exe", "dll", "so", "dylib",
    ];
    BINARY_EXTS
        .iter()
        .any(|known| ext.eq_ignore_ascii_case(known))
}

/// Reads an uncompressed blob up to `max_bytes`. When `sniff_binary_early` is
/// `true`, reads up to the 8 KiB sniff window BEFORE allocating the full
/// buffer so unknown binary files stop without allocating megabytes.
pub(crate) fn read_identity_bounded(
    full_path: &Path,
    max_bytes: usize,
    sniff_binary_early: bool,
) -> std::result::Result<BlobRead, DecompressionReadError> {
    let mut file = File::open(full_path).map_err(DecompressionReadError::Io)?;
    if max_bytes == 0 {
        return Ok(BlobRead {
            bytes: Vec::new(),
            truncated: file.metadata().map(|m| m.len() > 0).unwrap_or(false),
        });
    }
    let file_len = file.metadata().map(|m| m.len()).unwrap_or(0) as usize;
    let mut out = Vec::new();
    let mut chunk = [0u8; 16 * 1024];

    if sniff_binary_early {
        let sniff_limit = BINARY_SNIFF_BYTES.min(max_bytes);
        out.reserve(file_len.min(sniff_limit));
        while out.len() < sniff_limit {
            let want = (sniff_limit - out.len()).min(chunk.len());
            let n = file
                .read(&mut chunk[..want])
                .map_err(DecompressionReadError::Io)?;
            if n == 0 {
                return Ok(BlobRead {
                    bytes: out,
                    truncated: false,
                });
            }
            out.extend_from_slice(&chunk[..n]);
        }
        if spacefast_secret_scan::is_likely_binary(&out) {
            return Ok(BlobRead {
                bytes: out,
                truncated: false,
            });
        }
        let target = file_len.min(max_bytes);
        if target > out.len() {
            out.reserve(target - out.len());
        }
    } else {
        out.reserve(file_len.min(max_bytes));
    }

    let mut truncated = false;
    loop {
        if out.len() >= max_bytes {
            // Probe 1 byte to know whether the file actually exceeds max_bytes.
            let n = file
                .read(&mut chunk[..1])
                .map_err(DecompressionReadError::Io)?;
            truncated = n > 0;
            break;
        }
        let want = (max_bytes - out.len()).min(chunk.len());
        let n = file
            .read(&mut chunk[..want])
            .map_err(DecompressionReadError::Io)?;
        if n == 0 {
            break;
        }
        out.extend_from_slice(&chunk[..n]);
    }

    Ok(BlobRead {
        bytes: out,
        truncated,
    })
}

/// Reads and decompresses a `.gz` or `.br` blob up to `max_bytes`.
/// When `enforce_bomb_limits` is `true` (precompressed serving companions),
/// enforces absolute decoded byte and expansion-ratio ceilings across the full
/// stream (when `verify_full_stream` is `true`) or up to `max_bytes`.
/// When `enforce_bomb_limits` is `false` (standalone downloadable archives),
/// stops reading strictly at `max_bytes` and reports `truncated = true` instead
/// of failing the publish on high compression ratios.
pub(crate) fn read_compressed_bounded(
    full_path: &Path,
    encoding: CompressionEncoding,
    max_bytes: usize,
    verify_full_stream: bool,
    enforce_bomb_limits: bool,
) -> std::result::Result<BlobRead, DecompressionReadError> {
    let file = File::open(full_path).map_err(DecompressionReadError::Io)?;
    let compressed_bytes = file.metadata().map(|m| m.len()).unwrap_or(0).max(1);

    let mut reader: Box<dyn Read> = match encoding {
        CompressionEncoding::Gzip => Box::new(flate2::read::MultiGzDecoder::new(file)),
        CompressionEncoding::Brotli => Box::new(brotli::Decompressor::new(file, 8192)),
        CompressionEncoding::Identity => Box::new(file),
    };

    let mut out = Vec::with_capacity(max_bytes.min(64 * 1024));
    let mut chunk = [0u8; 16 * 1024];
    let mut decoded_total = 0u64;
    let mut truncated = false;

    loop {
        let n = reader
            .read(&mut chunk)
            .map_err(DecompressionReadError::Io)?;
        if n == 0 {
            break;
        }
        decoded_total = decoded_total.saturating_add(n as u64);
        if enforce_bomb_limits {
            if decoded_total > MAX_DECOMPRESSED_BYTES {
                return Err(DecompressionReadError::LimitExceeded {
                    decoded_bytes: decoded_total,
                    compressed_bytes,
                    reason: DecompressionLimitReason::SizeExceeded,
                });
            }
            if decoded_total >= RATIO_CHECK_MIN_DECODED_BYTES
                && decoded_total / compressed_bytes > MAX_DECOMPRESSION_RATIO
            {
                return Err(DecompressionReadError::LimitExceeded {
                    decoded_bytes: decoded_total,
                    compressed_bytes,
                    reason: DecompressionLimitReason::RatioExceeded,
                });
            }
        }

        if out.len() < max_bytes {
            let remaining = max_bytes - out.len();
            let take = remaining.min(n);
            out.extend_from_slice(&chunk[..take]);
            if take < n {
                truncated = true;
                if !verify_full_stream {
                    break;
                }
            }
        } else {
            truncated = true;
            if !verify_full_stream {
                break;
            }
        }
    }

    Ok(BlobRead {
        bytes: out,
        truncated,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use flate2::{write::GzEncoder, Compression};
    use std::io::Write;

    #[test]
    fn rejects_high_ratio_gzip_bomb() {
        let temp = tempfile::tempdir().unwrap();
        let path = temp.path().join("bomb.txt.gz");
        let mut encoder = GzEncoder::new(Vec::new(), Compression::best());
        // 2 MiB of zeros compresses to ~2 KiB (> 500:1 ratio).
        let zeros = vec![b'0'; 2 * 1024 * 1024];
        encoder.write_all(&zeros).unwrap();
        let compressed = encoder.finish().unwrap();
        std::fs::write(&path, &compressed).unwrap();

        let err = read_compressed_bounded(&path, CompressionEncoding::Gzip, 1024, true, true)
            .err()
            .expect("must reject bomb");
        assert!(matches!(
            err,
            DecompressionReadError::LimitExceeded {
                reason: DecompressionLimitReason::RatioExceeded,
                ..
            }
        ));

        // Standalone downloadable archives (enforce_bomb_limits = false) stop
        // cleanly at max_bytes instead of blocking finalize.
        let non_companion =
            read_compressed_bounded(&path, CompressionEncoding::Gzip, 1024, false, false).unwrap();
        assert!(non_companion.truncated);
        assert_eq!(non_companion.bytes.len(), 1024);
    }

    #[test]
    fn allows_ordinary_compressed_asset_and_sniffs_binary_early() {
        let temp = tempfile::tempdir().unwrap();
        let gz_path = temp.path().join("app.js.gz");
        let mut encoder = GzEncoder::new(Vec::new(), Compression::default());
        encoder
            .write_all(b"console.log('hello spacefast');")
            .unwrap();
        let compressed = encoder.finish().unwrap();
        std::fs::write(&gz_path, &compressed).unwrap();

        let read =
            read_compressed_bounded(&gz_path, CompressionEncoding::Gzip, 4096, true, true).unwrap();
        assert!(!read.truncated);
        assert_eq!(read.bytes, b"console.log('hello spacefast');");

        // Unknown binary file stops at 8 KiB instead of reading all 128 KiB.
        let bin_path = temp.path().join("model.bin");
        let mut payload = vec![b'a'; 128 * 1024];
        payload[100] = 0;
        std::fs::write(&bin_path, &payload).unwrap();
        let bin_read = read_identity_bounded(&bin_path, 128 * 1024, true).unwrap();
        assert!(!bin_read.truncated);
        assert_eq!(bin_read.bytes.len(), BINARY_SNIFF_BYTES);
    }
}
