//! The site a command runs against, and WP-CLI on it.
//!
//! `--site` absent is the dev site of the project in the current directory; a directory is that
//! project's dev site, or an offline deployment workspace; `@name` is a WP-CLI alias run with the
//! `wp` on `PATH`; an `http(s)://` URL is for the commands that speak HTTP (`sync`, `doctor`).
use std::path::{Path, PathBuf};
use std::process::{Command, ExitCode};

pub(crate) enum Site {
    Dev(PathBuf),
    Workspace(PathBuf),
    Alias(String),
    Url(String),
}

impl Site {
    /// `project` is the default directory: the current one, or a command's own project argument.
    pub(crate) fn resolve(site: Option<&str>, project: &Path) -> Site {
        match site {
            None => Site::Dev(project.to_path_buf()),
            Some(alias) if alias.starts_with('@') => Site::Alias(alias.to_string()),
            Some(url) if url.starts_with("http://") || url.starts_with("https://") => Site::Url(url.to_string()),
            Some(dir) if is_workspace(Path::new(dir)) => Site::Workspace(PathBuf::from(dir)),
            Some(dir) => Site::Dev(PathBuf::from(dir)),
        }
    }

    /// Run WP-CLI on this site with `args` and the caller's stdio; the exit status is WP-CLI's.
    /// A workspace answers with the deploy adapter's receipt (native output stays in a private file).
    pub(crate) fn wp(&self, args: &[String]) -> Result<ExitCode, String> {
        let mut command = match self {
            Site::Dev(project) => {
                let project = project
                    .canonicalize()
                    .map_err(|e| format!("{}: {e}", project.display()))?;
                let mut command = super::dev::wp_command(&project)?;
                command.args(args);
                command
            }
            Site::Alias(alias) => {
                let mut command = Command::new("wp");
                command.arg(alias).args(args);
                command
            }
            Site::Workspace(workspace) => {
                let mut adapter = vec![
                    "wp".to_string(),
                    "--workspace".into(),
                    workspace.display().to_string(),
                    "--".into(),
                ];
                adapter.extend_from_slice(args);
                return Ok(super::deploy::adapter(&adapter));
            }
            Site::Url(url) => {
                return Err(format!(
                    "{url} is a URL; WP-CLI needs the site's files. Pass a WP-CLI alias (--site @name, from wp-cli.yml) or a project directory."
                ));
            }
        };
        let status = command.status().map_err(|e| match self {
            Site::Alias(_) => format!("cannot run wp (WP-CLI) from PATH: {e}"),
            _ => format!("zero needs Node.js 22+ and npm (npx) for the dev site: {e}"),
        })?;
        Ok(ExitCode::from(status.code().unwrap_or(1).clamp(0, 255) as u8))
    }
}

/// An offline deployment workspace (`zero deploy init`) carries its adapter's owner record.
fn is_workspace(dir: &Path) -> bool {
    std::fs::read(dir.join("owner.json"))
        .ok()
        .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok())
        .is_some_and(|owner| owner["adapter"] == "zero-offline-wordpress-v1")
}

/// Run WP-CLI on the site and exit with its status; stdin, stdout and stderr are the caller's.
pub(crate) fn forward(site: Option<&str>, args: &[String]) -> ExitCode {
    let cwd = std::env::current_dir().unwrap_or_else(|_| PathBuf::from("."));
    Site::resolve(site, &cwd).wp(args).unwrap_or_else(|error| {
        eprintln!("error: {error}");
        ExitCode::from(2)
    })
}

/// Take `--site <value>` or `--site=<value>` out of `args`.
pub(crate) fn take_site(args: &mut Vec<String>) -> Result<Option<String>, String> {
    let Some(at) = args.iter().position(|a| a == "--site" || a.starts_with("--site=")) else {
        return Ok(None);
    };
    let flag = args.remove(at);
    let value = match flag.strip_prefix("--site=") {
        Some(value) => value.to_string(),
        None if at < args.len() => args.remove(at),
        None => return Err("--site needs a project directory, a deployment workspace, @alias or URL".into()),
    };
    if value.is_empty() {
        return Err("--site needs a project directory, a deployment workspace, @alias or URL".into());
    }
    if args.iter().any(|a| a == "--site" || a.starts_with("--site=")) {
        return Err("--site given twice".into());
    }
    Ok(Some(value))
}
