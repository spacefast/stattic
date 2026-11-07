//! The local WordPress lifecycle. Studio supplies native PHP, WordPress, SQLite and WP-CLI.
use std::path::{Path, PathBuf};
use std::process::{Command, ExitCode};

const STUDIO: &str = "wp-studio@2.1.1";
const TOOLS: &[(&str, &str)] = &[
    ("studio.mjs", include_str!("../../scripts/dev/studio.mjs")),
    ("setup.php", include_str!("../../scripts/dev/setup.php")),
];

fn runner() -> Result<PathBuf, String> {
    use sha2::{Digest, Sha256};
    let cache = std::env::var_os("XDG_CACHE_HOME")
        .filter(|s| !s.is_empty())
        .map(PathBuf::from)
        .or_else(|| std::env::var_os("LOCALAPPDATA").map(PathBuf::from))
        .or_else(|| std::env::var_os("HOME").map(|p| PathBuf::from(p).join(".cache")))
        .ok_or("set XDG_CACHE_HOME to a writable cache directory")?;
    let mut hash = Sha256::new();
    for (name, source) in TOOLS {
        hash.update(name.as_bytes());
        hash.update(source.as_bytes());
    }
    let digest: String = hash.finalize().iter().map(|byte| format!("{byte:02x}")).collect();
    let dir = cache.join("zero/dev").join(&digest[..16]);
    std::fs::create_dir_all(&dir).map_err(|e| e.to_string())?;
    for (name, source) in TOOLS {
        let path = dir.join(name);
        if std::fs::read(&path).is_ok_and(|bytes| bytes == source.as_bytes()) {
            continue;
        }
        let mut tmp = tempfile::NamedTempFile::new_in(&dir).map_err(|e| e.to_string())?;
        std::io::Write::write_all(&mut tmp, source.as_bytes()).map_err(|e| e.to_string())?;
        tmp.persist(path).map_err(|e| e.to_string())?;
    }
    Ok(dir.join("studio.mjs"))
}

fn command(project: &Path, mode: &str) -> Result<Command, String> {
    let mut command = Command::new(if cfg!(windows) { "npx.cmd" } else { "npx" });
    command.args(["--yes", "--package", STUDIO, "--", "node"]);
    command.arg(runner()?).arg(mode).arg(project);
    Ok(command)
}

/// Used by legacy migration too, so its default target is the same local site as `zero dev`.
pub(super) fn wp_command(project: &Path) -> Result<Command, String> {
    command(project, "wp")
}

pub(super) fn run(args: &crate::cli::Args) -> ExitCode {
    let result = (|| -> Result<ExitCode, String> {
        let project = args
            .project
            .canonicalize()
            .map_err(|e| format!("{}: {e}", args.project.display()))?;
        let mode = args.rest.first().map(String::as_str).unwrap_or("start");
        let mut command = command(&project, mode)?;
        if mode == "start" {
            command
                .arg(std::env::current_exe().map_err(|e| e.to_string())?)
                .arg(args.out.clone().unwrap_or_else(|| project.join(".zero/wp-content")))
                .arg(args.port.to_string());
            if args.no_watch {
                command.arg("--no-watch");
            }
            if args.allow_destructive {
                command.arg("--allow-destructive");
            }
        } else if mode == "wp" {
            command.args(args.rest.iter().skip(1));
        }
        let status = command
            .status()
            .map_err(|e| format!("zero dev needs Node.js 22+ and npm (npx): {e}"))?;
        Ok(ExitCode::from(status.code().unwrap_or(1).clamp(0, 255) as u8))
    })();
    match result {
        Ok(code) => code,
        Err(error) => {
            eprintln!("error: {error}");
            ExitCode::from(1)
        }
    }
}
