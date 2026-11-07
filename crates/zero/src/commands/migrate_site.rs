//! `zero migrate site <content-model.php> [--dry-run] [--space <id>] [--wp "<wp-cli command>"]`
//!
//! Runs the runtime's `wp zero migrate-legacy` against a WordPress site with the new app active.
//! The original release is sent on stdin, so the target can be the local Studio site (the default),
//! a container, or `wp --ssh=<host>`.
//! The PHP command owns the migration; this wrapper only transports it and keeps its exit status.
use std::io::Write;
use std::path::PathBuf;
use std::process::{Command, ExitCode, Stdio};

const USAGE: &str =
    "usage: zero migrate site <content-model.php> [--dry-run] [--space <id>] [--wp \"<wp-cli command>\"]";

struct Args {
    release: PathBuf,
    dry_run: bool,
    space: Option<String>,
    wp: Option<String>,
}

fn args(rest: &[String]) -> Result<Args, String> {
    let mut it = rest.iter();
    let mut release = None;
    let mut dry_run = false;
    let mut space = None;
    let mut wp = None;
    while let Some(arg) = it.next() {
        match arg.as_str() {
            "--dry-run" => dry_run = true,
            "--space" if space.is_none() => space = Some(it.next().ok_or("--space needs a Space id")?.clone()),
            flag if flag.starts_with("--space=") && space.is_none() => {
                space = Some(flag["--space=".len()..].to_string())
            }
            "--wp" if wp.is_none() => wp = Some(it.next().ok_or("--wp needs a WP-CLI command")?.clone()),
            flag if flag.starts_with('-') => return Err(format!("unknown or repeated flag {flag}\n{USAGE}")),
            _ if release.is_none() => release = Some(PathBuf::from(arg)),
            _ => return Err(USAGE.into()),
        }
    }
    Ok(Args {
        release: release.ok_or(USAGE)?,
        dry_run,
        space,
        wp,
    })
}

/// The WP-CLI invocation: `--wp` split on whitespace, or this project's local Studio site.
fn wp_command(wp: Option<&str>) -> Result<Command, String> {
    if let Some(cmd) = wp {
        let mut parts = cmd.split_whitespace();
        let program = parts.next().ok_or("--wp is empty")?;
        let mut command = Command::new(program);
        command.args(parts);
        return Ok(command);
    }
    super::dev::wp_command(&std::env::current_dir().map_err(|e| e.to_string())?)
}

pub(crate) fn cli(rest: &[String]) -> ExitCode {
    let args = match args(rest) {
        Ok(a) => a,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(2);
        }
    };
    let release = match std::fs::read(&args.release) {
        Ok(bytes) => bytes,
        Err(e) => {
            eprintln!("cannot read {}: {e}", args.release.display());
            return ExitCode::from(2);
        }
    };
    let mut command = match wp_command(args.wp.as_deref()) {
        Ok(c) => c,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(2);
        }
    };
    command.args(["zero", "migrate-legacy", "-"]);
    if args.dry_run {
        command.arg("--dry-run");
    }
    if let Some(space) = &args.space {
        command.arg(format!("--space={space}"));
    }
    let child = command
        .stdin(Stdio::piped())
        .stdout(Stdio::inherit())
        .stderr(Stdio::inherit())
        .spawn();
    let mut child = match child {
        Ok(c) => c,
        Err(e) => {
            eprintln!("cannot run WP-CLI: {e}");
            return ExitCode::from(1);
        }
    };
    if let Some(mut stdin) = child.stdin.take()
        && let Err(e) = stdin.write_all(&release)
    {
        eprintln!("cannot send the release to WP-CLI: {e}");
        return ExitCode::from(1);
    }
    match child.wait() {
        Ok(status) if status.success() => ExitCode::SUCCESS,
        Ok(_) => ExitCode::from(1),
        Err(e) => {
            eprintln!("WP-CLI failed: {e}");
            ExitCode::from(1)
        }
    }
}
