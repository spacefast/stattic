//! Run the optional site asset build on the author machine before compilation or capture.
use crate::diag::{Diag, Result, Span};
use std::fs::{File, OpenOptions};
use std::io::Write;
use std::path::Path;
use std::process::{Command, Stdio};

/// Builds the project's frontend assets and answers the lock that keeps another build from
/// rewriting them: keep it alive until the caller has read `public/`.
pub(crate) fn prepare_styles(project: &Path) -> Result<Option<File>> {
    let error = |message: String| Diag::new(Span::NONE, message).code(crate::diag::Code::Import);
    if !crate::layout::project_config(|p| p.is_file(), project).is_file() {
        return Ok(None);
    }
    if !crate::loader::frontend_enabled(&crate::loader::Disk, project)? {
        return Ok(None);
    }
    let guard = (|| -> std::io::Result<File> {
        let state = project.join(".tp");
        std::fs::create_dir_all(&state)?;
        let lock = OpenOptions::new()
            .read(true)
            .write(true)
            .create(true)
            .truncate(false)
            .open(state.join("frontend.lock"))?;
        lock.try_lock().map_err(std::io::Error::other)?;
        Ok(lock)
    })()
    .map_err(|e| error(format!("can't lock frontend assets for this build: {e}")))?;
    rebuild_entries(project)?;
    let styles = crate::loader::stylesheet_inputs(project)?;
    if styles.is_empty() && !project.join(crate::loader::FRONTEND_STYLES).is_file() {
        // The browser entries were just rebuilt into public/: the lock still covers reading them.
        return Ok(Some(guard));
    }

    let input = serde_json::to_vec(&styles).expect("paths are strings");
    let mut child = Command::new("node")
        .current_dir(project)
        .args([
            "--input-type=module",
            "--eval",
            r#"
import { readFileSync } from 'node:fs';
import { buildStyles } from '@typedpress/frontend';
await buildStyles({ root: process.cwd(), styles: JSON.parse(readFileSync(0, 'utf8')) });
"#,
        ])
        .stdin(Stdio::piped())
        .stdout(Stdio::inherit())
        .stderr(Stdio::inherit())
        .spawn()
        .map_err(|e| error(format!("can't start the frontend build with Node.js: {e}")))?;
    let write = child.stdin.take().expect("stdin is piped").write_all(&input);
    let status = child
        .wait()
        .map_err(|e| error(format!("can't wait for the frontend build: {e}")))?;
    if !status.success() {
        return Err(error(format!("frontend build failed ({status})"))
            .help("Install the project's dependencies, including @typedpress/frontend and tailwindcss, and check the output above."));
    }
    write.map_err(|e| error(format!("can't send stylesheet imports to the frontend build: {e}")))?;
    Ok(Some(guard))
}

/// `typedpress dev` sets this for the builds it runs: it serves the browser entries' sources through
/// Vite, so rebuilding their bundle on every save would be wasted work.
pub(crate) const DEV_SERVES_ENTRIES: &str = "TP_DEV_SERVES_FRONTEND";

/// The browser entries `@typedpress/frontend` built in this project before (`.tp/frontend-dev.json`
/// records them), built again before anything reads `public/typedpress-assets`: `typedpress dev` never
/// rewrites that bundle, so a `build`, `push` or `export` after a dev session would otherwise ship
/// the one from before the session's edits. A project with no record (a fresh checkout) is left to
/// its own build script, as before.
fn rebuild_entries(project: &Path) -> Result<()> {
    let error = |message: String| Diag::new(Span::NONE, message).code(crate::diag::Code::Import);
    if std::env::var_os(DEV_SERVES_ENTRIES).is_some() || !project.join(".tp/frontend-dev.json").is_file() {
        return Ok(());
    }
    let status = Command::new("node")
        .current_dir(project)
        .args([
            "--input-type=module",
            "--eval",
            r#"
import { rebuildFrontend } from '@typedpress/frontend';
await rebuildFrontend({ root: process.cwd() });
"#,
        ])
        .stdin(Stdio::null())
        // The command's own standard output is its report (`built … -> …`); Vite's goes to stderr.
        .stdout(std::io::stderr())
        .stderr(Stdio::inherit())
        .status()
        .map_err(|e| error(format!("can't start the frontend build with Node.js: {e}")))?;
    if !status.success() {
        return Err(error(format!("the browser entries in public/typedpress-assets could not be rebuilt ({status})"))
            .help("Fix the error above, or run the project's own frontend build. The existing bundle may be older than the sources."));
    }
    Ok(())
}
