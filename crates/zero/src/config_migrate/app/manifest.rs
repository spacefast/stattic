//! A 0.8 app read from its stored finalize manifest instead of its source tree.
//!
//! Every live 0.8 version keeps the finalize payload it published: the artifact (schema, handler
//! names, endpoint routes, pages and the source file list with hashes) and one bundled JavaScript
//! source per endpoint. The bundle is the esbuild output of the whole server entry, so it carries
//! no TypeScript types and cannot be turned back into the authored handlers. This module checks
//! everything the manifest decides on its own, with the same refusals as a source conversion, and
//! then refuses at `/source` until the original files are supplied and match the stored hashes.
use super::super::{Entry, Failure, entry, fail, pointer};
use crate::loader::Fs;
use serde_json::{Map, Value};
use sha2::{Digest, Sha256};
use std::path::Path;

/// The reason every manifest-only check ends with; censuses match on this prefix.
pub(super) const NOT_RECOVERABLE: &str = "source not recoverable";

/// The finalize payload's artifact: `{ artifact, endpointSources, ... }` or a bare artifact.
pub(super) struct Manifest {
    artifact: Map<String, Value>,
    endpoint_sources: Vec<Value>,
}

pub(super) fn parse(text: &str) -> Result<Manifest, Failure> {
    let value: Value =
        serde_json::from_str(text).map_err(|e| fail("", format!("finalize manifest is not JSON: {e}")))?;
    let Value::Object(mut root) = value else {
        return Err(fail("", "finalize manifest must be an object"));
    };
    let (artifact, endpoint_sources) = match root.remove("artifact") {
        Some(Value::Object(artifact)) => (
            artifact,
            match root.remove("endpointSources") {
                Some(Value::Array(sources)) => sources,
                _ => vec![],
            },
        ),
        Some(_) => return Err(fail("/artifact", "artifact must be an object")),
        None => (root, vec![]),
    };
    if artifact.get("format").and_then(Value::as_str) != Some("stattic.zero.capsule.v1") {
        return Err(fail(
            "/artifact/format",
            "not a Zero 0.8 capsule artifact (format stattic.zero.capsule.v1)",
        ));
    }
    Ok(Manifest {
        artifact,
        endpoint_sources,
    })
}

impl Manifest {
    fn server(&self) -> Option<&Map<String, Value>> {
        self.artifact.get("server").and_then(Value::as_object)
    }

    /// Checks what the manifest alone decides; returns the entries and refusals it found.
    pub(super) fn check(&self, entries: &mut Vec<Entry>, errors: &mut Vec<Failure>) {
        let server = self.server();
        let names = |key: &str| -> Vec<&str> {
            server
                .and_then(|s| s.get(key))
                .and_then(Value::as_array)
                .map(|v| v.iter().filter_map(Value::as_str).collect())
                .unwrap_or_default()
        };
        for socket in names("sockets") {
            errors.push(fail(
                &pointer("/sockets", socket),
                "unsupported capsule feature; port it before migrating the application",
            ));
        }
        // Each declared endpoint must have a v2 route at its method and path, as in a source run.
        let endpoints = server
            .and_then(|s| s.get("endpoints"))
            .and_then(Value::as_array)
            .cloned()
            .unwrap_or_default();
        for endpoint in &endpoints {
            let method = endpoint.get("method").and_then(Value::as_str).unwrap_or_default();
            let path = endpoint.get("path").and_then(Value::as_str).unwrap_or_default();
            // The artifact keeps routes, not the capsule's keys; method and path locate the endpoint.
            let at = pointer("/endpoints", &format!("{method} {path}"));
            let read_only = match endpoint.get("mode").and_then(Value::as_str) {
                Some("read") => Some(true),
                Some("write") => Some(false),
                _ => None,
            };
            if let Err(e) = super::endpoints::route(method, path, read_only, &at) {
                errors.push(e);
            }
        }
        let bundled = self
            .endpoint_sources
            .iter()
            .filter(|s| s.get("source").and_then(Value::as_str).is_some_and(|s| !s.is_empty()))
            .count();
        if bundled > 0 {
            entries.push(entry(
                "/endpointSources",
                "review",
                format!(
                    "{bundled} bundled endpoint source(s) are compiled JavaScript without types; they locate the endpoints but cannot be converted"
                ),
            ));
        }
        for (i, page) in self
            .artifact
            .get("pages")
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .enumerate()
        {
            let path = page.get("path").and_then(Value::as_str).unwrap_or_default();
            let at = pointer("/pages", &i.to_string());
            match page.get("render").and_then(Value::as_str) {
                // Document pages are rendered at publish from content; v2 needs a TSX page.
                Some("document") => errors.push(fail(
                    &at,
                    format!("document page {path} has no v2 counterpart; it needs a TSX page"),
                )),
                // A client page converts only from its source, which only `/source` can supply.
                Some("client") => {}
                _ => errors.push(fail(&at, "unknown page render mode")),
            }
        }
        for role in self
            .artifact
            .get("connectors")
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
        {
            let name = role
                .get("name")
                .or_else(|| role.get("role"))
                .and_then(Value::as_str)
                .unwrap_or("connector");
            entries.push(entry(
                &pointer("/connectors", name),
                "review",
                "connector roles move with the source conversion",
            ));
        }
    }

    /// The source files' hashes, each `(path, sha256)`, relative to the source root.
    /// `sourceRoot` is the publisher's own build directory, so only the relative paths are kept.
    fn files(&self) -> Vec<(String, String)> {
        self.artifact
            .get("sourceManifest")
            .and_then(|m| m.get("files"))
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|f| {
                Some((
                    f.get("path")?.as_str()?.to_string(),
                    f.get("sha256")?.as_str()?.to_string(),
                ))
            })
            .collect()
    }

    /// The server entry the published version declared (`sourceManifest.entries.server`).
    pub(super) fn server_entry(&self) -> Option<&str> {
        self.artifact
            .get("sourceManifest")?
            .get("entries")?
            .get("server")?
            .as_str()
    }

    /// Refuses unless every recorded source file is present under `root` with its stored hash.
    /// Returns exactly those files, as read, so a conversion can see nothing the publish did not.
    pub(super) fn verify(
        &self,
        fs: &dyn Fs,
        root: &Path,
    ) -> Result<std::collections::BTreeMap<String, String>, Failure> {
        let files = self.files();
        if files.is_empty() {
            return Err(fail(
                "/source",
                format!("{NOT_RECOVERABLE}: the manifest records no source files to check against"),
            ));
        }
        let mut differing = vec![];
        let mut verified = std::collections::BTreeMap::new();
        for (path, expected) in &files {
            let relative = Path::new(path);
            if relative.is_absolute()
                || relative
                    .components()
                    .any(|c| !matches!(c, std::path::Component::Normal(_)))
            {
                return Err(fail(
                    "/source",
                    format!("manifest source path {path:?} leaves the source root"),
                ));
            }
            match fs.read_to_string(&root.join(relative)) {
                Ok(text) if hex(&Sha256::digest(text.as_bytes())) == *expected => {
                    verified.insert(path.clone(), text);
                }
                Ok(_) => differing.push(format!("{path} (changed)")),
                Err(e) if e.kind() == std::io::ErrorKind::NotFound => differing.push(format!("{path} (missing)")),
                // A recorded binary asset (an image under public/) is not text, and the converter
                // never reads it: compare its bytes and leave it out of the converted files.
                Err(e) if e.kind() == std::io::ErrorKind::InvalidData => match std::fs::read(root.join(relative)) {
                    Ok(bytes) if hex(&Sha256::digest(&bytes)) == *expected => {}
                    Ok(_) => differing.push(format!("{path} (changed)")),
                    Err(e) => differing.push(format!("{path} (unreadable: {e})")),
                },
                Err(e) => differing.push(format!("{path} (unreadable: {e})")),
            }
        }
        if differing.is_empty() {
            return Ok(verified);
        }
        differing.truncate(5);
        Err(fail(
            "/source",
            format!("source differs from the published version: {}", differing.join(", ")),
        ))
    }
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}
