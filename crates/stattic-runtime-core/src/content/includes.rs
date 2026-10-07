//! Local HTML includes are compile inputs, never a serving-time capability.
use std::collections::{BTreeMap, BTreeSet};
use std::path::{Path, PathBuf};

use lol_html::{doc_comments, rewrite_str, RewriteStrSettings};

use super::PIPELINE_SOURCE_MAX_BYTES;
use crate::finalize::{invalid, read_bounded, FinalizeError, Result};

const MAX_DEPTH: usize = 32;
const MAX_INCLUDES: usize = 1024;
pub(super) const PREFIX: &str = "<!--#include virtual=\"/parts/";

pub(super) fn is_html(path: &str) -> bool {
    let lower = path.to_ascii_lowercase();
    lower.ends_with(".html") || lower.ends_with(".htm")
}

pub(super) struct Includes<'a> {
    root: &'a Path,
    manifest: BTreeSet<String>,
    pub(super) referenced: BTreeSet<String>,
    // Cache source, not expanded output: every use must walk its own ancestry
    // and charge its own depth/count/byte budgets.
    sources: BTreeMap<String, String>,
}

impl<'a> Includes<'a> {
    pub(super) fn new(root: &'a Path, paths: impl Iterator<Item = String>) -> Self {
        Self {
            root,
            manifest: paths.collect(),
            referenced: BTreeSet::new(),
            sources: BTreeMap::new(),
        }
    }

    pub(super) fn resolve(&mut self, path: &str, source: &str) -> Result<String> {
        if !source.contains(PREFIX) {
            return Ok(source.to_string());
        }
        self.sources
            .entry(path.to_string())
            .or_insert_with(|| source.to_string());
        let mut output = String::new();
        self.expand(
            path,
            source,
            &mut vec![path.to_string()],
            &mut 0,
            &mut output,
        )?;
        Ok(output)
    }

    fn expand(
        &mut self,
        path: &str,
        source: &str,
        stack: &mut Vec<String>,
        count: &mut usize,
        output: &mut String,
    ) -> Result<()> {
        let mut comments = Vec::new();
        rewrite_str(
            source,
            RewriteStrSettings::new().append_document_content_handler(doc_comments!(|comment| {
                if source[comment.source_location().bytes()].starts_with(PREFIX) {
                    comments.push(comment.source_location().bytes());
                }
                Ok(())
            })),
        )
        .map_err(|error| FinalizeError::Invalid {
            code: "html_include_parse_failed",
            details: Some(serde_json::json!({"path":path})),
            message: format!("Cannot parse HTML includes in {path}: {error}"),
        })?;
        let mut cursor = 0;
        for range in comments {
            let fail = |reason: &str| {
                FinalizeError::Invalid {
                code: "html_include_invalid",
                details: Some(serde_json::json!({"path":path,"offset":range.start})),
                message: format!("Invalid HTML include in {path} at byte {}: {reason}. Use <!--#include virtual=\"/parts/header.html\" -->.", range.start),
            }
            };
            let name = source[range.clone()]
                .strip_prefix(PREFIX)
                .and_then(|name| name.strip_suffix(".html\" -->"))
                .filter(|name| {
                    !name.is_empty()
                        && name
                            .bytes()
                            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'_' | b'-'))
                })
                .ok_or_else(|| fail("expected the canonical comment with an ASCII filename"))?;
            let target = format!("parts/{name}.html");
            if !self.manifest.contains(&target) {
                return invalid("html_include_missing", format!("HTML include in {path} references /{target}, which is not in this version's staged manifest."));
            }
            if stack.contains(&target) {
                return invalid(
                    "html_include_cycle",
                    format!("HTML include cycle: {} -> {target}.", stack.join(" -> ")),
                );
            }
            *count += 1;
            if stack.len() > MAX_DEPTH || *count > MAX_INCLUDES {
                return invalid("html_include_limit", format!("HTML include in {path} exceeds {MAX_DEPTH} nested includes or {MAX_INCLUDES} includes per page ({} -> {target}).", stack.join(" -> ")));
            }
            append(output, &source[cursor..range.start], path)?;
            let fragment = self.source(&target, path)?;
            stack.push(target.clone());
            self.expand(&target, &fragment, stack, count, output)?;
            stack.pop();
            self.referenced.insert(target);
            cursor = range.end;
        }
        // The trailing source is part of the resolved page's budget too.
        append(output, &source[cursor..], path)
    }

    fn source(&mut self, target: &str, parent: &str) -> Result<String> {
        // Do not let a staged name grant access outside the version through a
        // symlink. Check even cached sources so reuse never skips validation.
        let root = self
            .root
            .canonicalize()
            .map_err(|error| include_io(parent, target, error))?;
        let file: PathBuf = self
            .root
            .join(target)
            .canonicalize()
            .map_err(|error| include_io(parent, target, error))?;
        if !file.starts_with(&root) {
            return invalid("html_include_escape", format!("HTML include in {parent} references /{target}, which resolves outside the staged version."));
        }
        let resolved = file.strip_prefix(&root).expect("checked containment");
        if !resolved
            .to_str()
            .is_some_and(|path| self.manifest.contains(path))
        {
            return invalid("html_include_missing", format!("HTML include /{target} from {parent} resolves to {}, which is not in this version's staged manifest.", resolved.display()));
        }
        if let Some(source) = self.sources.get(target) {
            return Ok(source.clone());
        }
        let bytes = read_bounded(&file, PIPELINE_SOURCE_MAX_BYTES).map_err(|error| {
            FinalizeError::Invalid {
                code: "html_include_read_failed",
                details: Some(serde_json::json!({"path":parent,"target":target})),
                message: format!("Cannot read HTML include /{target} from {parent}: {error}"),
            }
        })?;
        let source = String::from_utf8(bytes).map_err(|error| FinalizeError::Invalid {
            code: "html_include_invalid",
            details: Some(serde_json::json!({"path":parent,"target":target})),
            message: format!("HTML include /{target} from {parent} is not UTF-8: {error}"),
        })?;
        self.sources.insert(target.to_string(), source.clone());
        Ok(source)
    }
}

fn include_io(parent: &str, target: &str, error: std::io::Error) -> FinalizeError {
    FinalizeError::Invalid {
        code: "html_include_read_failed",
        details: Some(serde_json::json!({"path":parent,"target":target})),
        message: format!("Cannot read staged HTML include /{target} from {parent}: {error}"),
    }
}

fn append(output: &mut String, bytes: &str, path: &str) -> Result<()> {
    if bytes.len() > PIPELINE_SOURCE_MAX_BYTES.saturating_sub(output.len()) {
        return invalid(
            "html_include_too_large",
            format!("Resolved HTML page containing {path} exceeds the 2 MiB parsed HTML budget."),
        );
    }
    output.push_str(bytes);
    Ok(())
}
