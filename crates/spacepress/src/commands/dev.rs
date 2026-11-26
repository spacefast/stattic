//! The local WordPress lifecycle. Studio supplies native PHP, WordPress, SQLite and WP-CLI.
use std::path::{Path, PathBuf};
use std::process::{Command, ExitCode};

const STUDIO: &str = "wp-studio@2.2.0";
const TOOLS: &[(&str, &str)] = &[
    ("frontend.mjs", include_str!("../../scripts/dev/frontend.mjs")),
    ("studio.mjs", include_str!("../../scripts/dev/studio.mjs")),
    ("setup.php", include_str!("../../scripts/dev/setup.php")),
    ("live.mjs", include_str!("../../scripts/dev/live.mjs")),
    ("live-client.js", include_str!("../../scripts/dev/live-client.js")),
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
    let dir = cache.join("sp/dev").join(&digest[..16]);
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

/// `spacepress export`'s throwaway WordPress (scripts/dev/studio.mjs `export`): its arguments follow.
pub(super) fn export_command() -> Result<Command, String> {
    let mut command = Command::new(if cfg!(windows) { "npx.cmd" } else { "npx" });
    command.args(["--yes", "--package", STUDIO, "--", "node"]);
    command.arg(runner()?).arg("export");
    Ok(command)
}

/// WP-CLI on the project's dev site (`spacepress wp`, and the site commands without `--site`).
pub(super) fn wp_command(project: &Path) -> Result<Command, String> {
    command(project, "wp")
}

/// `spacepress dev` has created this project's site: its marker (scripts/dev/studio.mjs) says Studio
/// finished creating it.
pub(crate) fn created(project: &Path) -> bool {
    std::fs::read(project.join(".sp/dev/owner.json"))
        .ok()
        .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok())
        .is_some_and(|owner| owner["created"] == true)
}

/// Something accepts connections on the site's port. Studio serves the site on localhost.
fn accepts(port: u16) -> bool {
    use std::net::{TcpStream, ToSocketAddrs};
    ("localhost", port).to_socket_addrs().is_ok_and(|mut addresses| {
        addresses.any(|address| TcpStream::connect_timeout(&address, std::time::Duration::from_millis(750)).is_ok())
    })
}

/// The recorded `spacepress dev` process still exists.
fn alive(pid: u64, state: &Path) -> bool {
    if cfg!(unix) {
        // The shell's own `kill`: no program has to be on PATH for it.
        Command::new("/bin/sh")
            .args(["-c", "kill -0 \"$1\"", "sh", &pid.to_string()])
            .stdin(std::process::Stdio::null())
            .stderr(std::process::Stdio::null())
            .status()
            .is_ok_and(|status| status.success())
    } else {
        // The lock a running `spacepress dev` holds names its process (scripts/dev/studio.mjs).
        std::fs::read(state.join("session.json"))
            .ok()
            .and_then(|bytes| serde_json::from_slice::<serde_json::Value>(&bytes).ok())
            .is_some_and(|session| session["pid"] == pid)
    }
}

/// The project's dev site as data, for `spacepress dev status --json` and `spacepress mcp`'s `dev_status`.
/// `state` is `none` (never created), `stopped` or `running`; `watching` says whether a `spacepress dev`
/// process is rebuilding it on save, and `pid` is that process. `error` is that process's last build
/// when it failed (`message`, and the compiler's `diagnostics`), so a running site that does not
/// serve the current source says so; it is null after a build that succeeded. `project` is canonical.
///
/// It reads `.sp/dev/state.json`, which `spacepress dev` writes when the site is up, and checks the
/// port and the process itself, so it does not start Studio. A site started by a compiler from
/// before that file is asked of Studio.
pub(super) fn status(project: &Path) -> Result<serde_json::Value, String> {
    status_with(project, |mut studio| {
        studio
            .stdin(std::process::Stdio::null())
            .output()
            .map_err(|e| format!("spacepress needs Node.js 22+ and npm (npx) for the dev site: {e}"))
    })
}

/// [`status`], with `run` running Studio's own status when the project has no state file (a site
/// started by a compiler from before it). `spacepress mcp` passes a runner bounded by its timeout and
/// cancellation.
pub(super) fn status_with(
    project: &Path,
    run: impl FnOnce(Command) -> Result<std::process::Output, String>,
) -> Result<serde_json::Value, String> {
    use serde_json::{Value, json};
    let dir = project.join(".sp/dev");
    let read = |name: &str| {
        std::fs::read(dir.join(name))
            .ok()
            .and_then(|bytes| serde_json::from_slice::<Value>(&bytes).ok())
    };
    if !created(project) {
        return Ok(json!({ "state": "none", "start": "spacepress dev" }));
    }
    let report =
        |url: &str, port: u16, running: bool, pid: Option<u64>, [log, error]: [Value; 2], versions: [&Value; 2]| {
            json!({
                "state": if running { "running" } else { "stopped" },
                "url": url,
                "port": port,
                "admin": format!("{url}/wp-admin/"),
                "site": dir.join("wordpress"),
                "watching": running && pid.is_some(),
                "pid": pid,
                "log": log,
                "error": error,
                "wordpress": versions[0],
                "php": versions[1],
            })
        };
    if let Some(state) = read("state.json").filter(|state| state["version"] == 1)
        && let (Some(url), Some(port)) = (state["url"].as_str(), state["port"].as_u64())
        && let Ok(port) = u16::try_from(port)
    {
        let pid = state["pid"].as_u64().filter(|pid| alive(*pid, &dir));
        // The log and the build error are the recorded process's: nothing is said of one that is gone.
        let of_process = if pid.is_some() {
            [state["log"].clone(), state["error"].clone()]
        } else {
            [Value::Null, Value::Null]
        };
        return Ok(report(
            url,
            port,
            accepts(port),
            pid,
            of_process,
            [&state["wordpress"], &state["php"]],
        ));
    }
    let out = run(command(project, "status")?)?;
    let studio: Value = serde_json::from_slice(&out.stdout).unwrap_or_default();
    let Some(url) = studio["siteUrl"].as_str().filter(|_| out.status.success()) else {
        let said = String::from_utf8_lossy(&out.stderr);
        return Err(format!(
            "cannot read the dev site's status: {}",
            said.lines().rev().find(|l| !l.trim().is_empty()).unwrap_or("no output")
        ));
    };
    let url = url.trim_end_matches('/');
    let port = url::Url::parse(url)
        .ok()
        .and_then(|url| url.port_or_known_default())
        .unwrap_or(80);
    Ok(report(
        url,
        port,
        studio["isOnline"] == true,
        None,
        [Value::Null, Value::Null],
        [&studio["wpVersion"], &studio["phpVersion"]],
    ))
}

/// Whether a coding agent, not a person at a terminal, started this command: the output is not a
/// terminal and one of the variables agents set for the commands they run is present. `CI` wins:
/// a pipeline that runs `spacepress dev` as a step waits on it. Answers with the variable that decided.
fn agent_drives(terminal: bool, env: impl Fn(&str) -> Option<String>) -> Option<&'static str> {
    let set = |name: &str| env(name).is_some_and(|value| !matches!(value.as_str(), "" | "0" | "false"));
    if terminal || set("CI") {
        return None;
    }
    ["CLAUDECODE", "CODEX_SANDBOX", "AI_AGENT", "AGENT"]
        .into_iter()
        .find(|name| set(name))
}

/// The dev site's URL, for the commands that speak HTTP (`sync`) when `--site` is absent.
pub(super) fn url(project: &Path) -> Result<String, String> {
    let project = project
        .canonicalize()
        .map_err(|e| format!("{}: {e}", project.display()))?;
    let status = status(&project)?;
    match status["url"].as_str() {
        Some(url) if status["state"] == "running" => Ok(url.to_string()),
        _ => Err("the dev site is not running; start it with spacepress dev, or pass --site <url>".into()),
    }
}

pub(super) fn run(args: &crate::cli::Args) -> ExitCode {
    let result = (|| -> Result<ExitCode, String> {
        let project = args
            .project
            .canonicalize()
            .map_err(|e| format!("{}: {e}", args.project.display()))?;
        let mode = args.rest.first().map(String::as_str).unwrap_or("start");
        if mode == "status" && args.json {
            println!("{}", status(&project)?);
            return Ok(ExitCode::SUCCESS);
        }
        let mut command = command(&project, mode)?;
        if mode == "start" {
            // The watcher is a grandchild (npx, a shell, node) that a signal to this process never
            // reaches. It checks that this process still exists, and stops WordPress when it is gone.
            command.env("SP_DEV_PARENT", std::process::id().to_string());
            let layout = crate::layout::Layout::from_project(&project).unwrap_or_default();
            command
                .arg(std::env::current_exe().map_err(|e| e.to_string())?)
                .arg(args.out.clone().unwrap_or_else(|| project.join(layout.wp_content())))
                .arg(args.port.to_string());
            if args.no_watch {
                command.arg("--no-watch");
            } else if args.detach {
                command.arg("--detach");
            } else if !args.foreground {
                use std::io::IsTerminal as _;
                let agent = agent_drives(std::io::stdout().is_terminal(), |name| std::env::var(name).ok());
                if let Some(variable) = agent {
                    eprintln!(
                        "spacepress dev: running in the background, because {variable} is set and the output is not a terminal; --foreground keeps it attached"
                    );
                    command.arg("--detach");
                }
            }
            if args.allow_destructive {
                command.arg("--allow-destructive");
            }
            if let Some(url) = &args.dev_url {
                command.arg(format!("--url={url}"));
            }
            if let Some(host) = &args.dev_host {
                command.arg(format!("--host={host}"));
            }
        }
        let status = command
            .status()
            .map_err(|e| format!("spacepress dev needs Node.js 22+ and npm (npx): {e}"))?;
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

#[cfg(test)]
mod tests {
    use super::agent_drives;

    #[test]
    fn only_an_agent_without_a_terminal_and_outside_ci_puts_dev_in_the_background() {
        let env = |pairs: &'static [(&'static str, &'static str)]| {
            move |name: &str| {
                pairs
                    .iter()
                    .find(|(key, _)| *key == name)
                    .map(|(_, value)| value.to_string())
            }
        };
        assert_eq!(agent_drives(false, env(&[("CLAUDECODE", "1")])), Some("CLAUDECODE"));
        assert_eq!(
            agent_drives(false, env(&[("CODEX_SANDBOX", "seatbelt")])),
            Some("CODEX_SANDBOX")
        );
        assert_eq!(
            agent_drives(false, env(&[("AI_AGENT", "some-agent")])),
            Some("AI_AGENT")
        );
        assert_eq!(
            agent_drives(false, env(&[("AGENT", "1"), ("CI", "false")])),
            Some("AGENT")
        );
        // A person's terminal, a pipe with no agent, and a pipeline all stay attached.
        assert_eq!(agent_drives(true, env(&[("CLAUDECODE", "1")])), None);
        assert_eq!(agent_drives(false, env(&[])), None);
        assert_eq!(agent_drives(false, env(&[("CLAUDECODE", "1"), ("CI", "true")])), None);
        assert_eq!(agent_drives(false, env(&[("AGENT", "0")])), None);
        assert_eq!(agent_drives(false, env(&[("AGENT", "")])), None);
    }
}
