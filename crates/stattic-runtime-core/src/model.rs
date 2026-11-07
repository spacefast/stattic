use serde::{Deserialize, Serialize};
use serde_json::{Map, Value};

pub const SITE_FINALIZE_INPUT_FORMAT: &str = "stattic.runtime.finalize.input.v2";
pub const SITE_FINALIZE_OUTPUT_FORMAT: &str = "stattic.runtime.finalize.output.v2";

/// The filesystem-rooted finalize input (`stattic.runtime.finalize.input.v2`).
/// Rust walks, commits, and writes under `version_root` itself.
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct SiteFinalizeInput {
    pub format: String,
    /// The private storage root: `spaces/<spaceId>/versions/<versionId>` is
    /// created (immutably) under it and every declared byte is read from the
    /// per-space CAS at `spaces/<spaceId>/blobs/<aa>/<sha256>` beside it.
    pub version_root: String,
    pub space_id: String,
    pub version_id: String,
    #[serde(default)]
    pub upload_id: Option<String>,
    pub generated_at: String,
    #[serde(default)]
    pub session: Value,
    #[serde(default)]
    pub body: Value,
    #[serde(default)]
    pub zero_endpoints: Vec<RuntimeZeroEndpoint>,
    #[serde(default)]
    pub zero_runs: Vec<RuntimeZeroRun>,
}

/// The result envelope of a v2 site finalize
/// (`stattic.runtime.finalize.output.v2`).
#[derive(Debug, Clone, Serialize, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct SiteFinalizeOutput {
    pub format: String,
    pub space_id: String,
    pub version_id: String,
    pub file_count: usize,
    pub zero_endpoint_count: usize,
    pub diagnostics: Vec<RuntimeDiagnostic>,
    /// The scalars that stand in for a file list on the control plane's version
    /// row. Absent only when replaying a version finalized before the catalog
    /// existed.
    #[cfg(not(target_family = "wasm"))]
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub catalog_digests: Option<crate::catalog::CatalogDigests>,
    /// What this publish changed relative to the version it supersedes: the
    /// counts the changelog renders and the request paths the edge purge takes.
    /// Absent when the caller named no previous version, or when that version
    /// predates the catalog.
    #[cfg(not(target_family = "wasm"))]
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub delta: Option<crate::catalog::CatalogDelta>,
    /// What this run cost and how much of it was work. Absent on a replay,
    /// which runs no stage at all.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub telemetry: Option<FinalizeTelemetry>,
}

/// The per-stage cost of ONE finalize run.
///
/// This is EPHEMERAL: it rides the output envelope and nothing else. Timings
/// differ on every run, while `metadata.json` embeds `finalizeInputSha256` and
/// `debugJsonSha256` and a replayed finalize has to answer identically to the
/// first one — so nothing here may ever be written under the version root.
#[derive(Debug, Clone, Default, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct FinalizeTelemetry {
    /// Paths staged from the session (uploaded plus retained).
    pub staged_files: usize,
    /// Distinct paths the content pipeline wrote: rendered pages, files-mode
    /// Gutenberg documents, the compiled theme stylesheet, and decoration
    /// rewrites.
    pub generated_files: usize,
    /// The subset of those the HTML decoration pass rewrote.
    pub decorated_files: usize,
    /// Decoration targets that adopted the previous version's served identity
    /// under a matching context digest — the incremental publish gauge.
    pub skipped_files: usize,
    pub staging_ms: u64,
    #[serde(default)]
    pub zero_v2_compile_ms: u64,
    pub template_substitution_ms: u64,
    pub html_pipeline_ms: u64,
    /// Reading and compiling `_redirects` / `_headers` / routing config.
    #[serde(default)]
    pub conventions_ms: u64,
    /// Compiling the version's Zero endpoints and runs.
    #[serde(default)]
    pub zero_compile_ms: u64,
    pub blob_install_ms: u64,
    /// Compiling directory listings, charged separately from the response
    /// tables they feed.
    #[serde(default)]
    pub listings_ms: u64,
    pub response_tables_ms: u64,
    pub catalog_delta_ms: u64,
    /// Writing and validating the version artifacts: metadata, catalog, debug
    /// and Zero artifacts, plus the staging-workspace teardown.
    #[serde(default)]
    pub artifacts_write_ms: u64,
    /// The whole finalize, staging through rename. The stages above are
    /// disjoint slices of it and do not add up to it: template resolution,
    /// policy validation and the readiness projection are the remainder.
    pub total_ms: u64,
}

pub use crate::legacy_zero::{
    PhpActionRecord, RuntimeZeroEndpoint, RuntimeZeroRun, ZeroCapabilities, ZeroEndpointArtifact,
    ZeroEndpointIndexArtifact, ZeroExecutionMode, ZeroMigrationsArtifact, ZeroRouteEntry,
    ZeroRoutesArtifact, ZeroRunArtifact, ZeroRunIndexArtifact,
};

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct GeneratedRuntimeFile {
    pub path: String,
    pub bytes: Vec<u8>,
}

#[derive(Debug, Clone, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct RuntimeDiagnostic {
    pub severity: RuntimeDiagnosticSeverity,
    pub code: String,
    pub message: String,
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub path: Option<String>,
    /// What the diagnostic points AT beyond its file: the offending variable
    /// name, the source line's text, the colliding routes. The control plane
    /// renders these into the publish receipt, so dropping them here is what
    /// turns a pointed publish failure into "something went wrong".
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub details: Option<Map<String, Value>>,
}

#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum RuntimeDiagnosticSeverity {
    Info,
    Warning,
    Error,
}
