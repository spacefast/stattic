//! The `zero` command line.
//!
//! ```text
//! zero build [dir] [--out <dir>] [--quiet]   write the plugins to .zero/wp-content
//! zero check [dir] [--json]                  compile; write nothing (JSON diagnostics optional)
//! zero dev [dir] [--port <n>] [--no-watch]   local WordPress through Studio, without Docker
//! zero doctor [dir] [--out <dir>] [--site <url> --token <t>] [--json]
//! zero sync [status|resolve <uid>] [dir] --site <url> <credential>   files ⇄ records (src/commands/sync.rs)
//! ```

use std::path::PathBuf;
use std::process::ExitCode;

pub struct Args {
    pub(crate) command: String,
    pub(crate) project: PathBuf,
    pub(crate) out: Option<PathBuf>,
    pub(crate) port: u16,
    pub(crate) quiet: bool,
    pub(crate) rest: Vec<String>,
    pub(crate) site: Option<String>,
    pub(crate) token: Option<String>,
    pub(crate) json: bool,
    pub(crate) no_watch: bool,
    pub(crate) allow_destructive: bool,
    pub(crate) preserve_data: Option<PathBuf>,
}

pub const USAGE: &str = "usage: zero <build|check|dev|doctor|deploy|publish|backup|restore> [project-dir] [--out dir] [--port n] [--quiet]\n       zero dev [project-dir] [--port n] [--no-watch] [--allow-destructive]\n       zero dev <stop|status> [project-dir]\n       zero dev wp [project-dir] -- <WP-CLI arguments>\n       zero build [project-dir] [--preserve-data snapshot.json]\n       zero check [project-dir] [--json]\n       zero doctor [project-dir] [--out dir] --site <url> [--token <t>] [--json]\n       zero migrate app --from zero-0.8 <capsule-dir> --out <new-dir> [--space <id>] [--dry-run] [--json]\n       zero migrate config --from emdash|payload|sanity|zero <input> --out <new/zero.config.ts>\n       zero migrate site <content-model.php> [--dry-run] [--space <id>] [--wp \"<wp-cli command>\"]\n       zero sync [status | resolve <uid>] [dir] --site <url> --app-password <user:pass> | --bearer <jwt>\n       zero deploy <inspect|init|status|wp> <options>\n       zero publish --artifact dir [--baseline dir]\n       zero backup --workspace dir --output new.zip\n       zero restore --archive backup.zip --workspace new-dir --wp-cli file\n       Adapter scope and options: docs/deployment.md";

fn parse_args() -> Result<Args, String> {
    let mut it = std::env::args().skip(1);
    let command = it.next().ok_or(USAGE)?;
    let mut args = Args {
        command,
        project: PathBuf::from("."),
        out: None,
        port: 8787,
        quiet: false,
        rest: Vec::new(),
        site: None,
        token: None,
        json: false,
        no_watch: false,
        allow_destructive: false,
        preserve_data: None,
    };
    if args.command == "sync" {
        args.rest = it.collect();
        return Ok(args);
    }
    while let Some(a) = it.next() {
        match a.as_str() {
            "--" if args.command == "dev" && args.rest.first().is_some_and(|a| a == "wp") => {
                args.rest.extend(it);
                break;
            }
            "stop" | "status" | "wp" if args.command == "dev" && args.rest.is_empty() => args.rest.push(a),
            "--no-watch" if args.command == "dev" => args.no_watch = true,
            "--allow-destructive" if args.command == "dev" => args.allow_destructive = true,
            "--preserve-data" if args.command == "build" => {
                args.preserve_data = Some(PathBuf::from(it.next().ok_or("--preserve-data needs a snapshot path")?));
            }
            "--site" => args.site = Some(it.next().ok_or("--site needs a URL")?),
            "--token" => args.token = Some(it.next().ok_or("--token needs a bearer token")?),
            "--json" => args.json = true,
            "--out" => args.out = Some(PathBuf::from(it.next().ok_or("--out needs a directory")?)),
            "--port" => {
                args.port = it
                    .next()
                    .ok_or("--port needs a number")?
                    .parse()
                    .map_err(|_| "--port needs a number")?;
                if args.port == 0 {
                    return Err("--port must be between 1 and 65535".into());
                }
            }
            "--quiet" | "-q" => args.quiet = true,
            other if other.starts_with("--") => return Err(format!("unknown flag {other}\n{USAGE}")),
            other => args.project = PathBuf::from(other),
        }
    }
    if args.command != "doctor" && (args.site.is_some() || args.token.is_some()) {
        return Err("--site and --token are doctor options".into());
    }
    if args.json && !matches!(args.command.as_str(), "doctor" | "check") {
        return Err("--json is supported by doctor and check".into());
    }
    Ok(args)
}

pub fn main() -> ExitCode {
    let lifecycle = std::env::args().nth(1);
    let lifecycle_args: Vec<String> = std::env::args().skip(2).collect();
    match lifecycle.as_deref() {
        Some("sources") => return crate::bundle::sources_cli(&lifecycle_args),
        Some("compile-sources") => return crate::bundle::compile_cli(&lifecycle_args),
        Some("deploy") => return crate::commands::deploy::cli(&lifecycle_args),
        Some("publish" | "backup" | "restore") => {
            return crate::commands::deploy::cli(&std::env::args().skip(1).collect::<Vec<_>>());
        }
        _ => {}
    }
    if std::env::args().nth(1).as_deref() == Some("migrate") {
        if std::env::args().nth(2).as_deref() == Some("site") {
            return crate::commands::migrate_site(&std::env::args().skip(3).collect::<Vec<_>>());
        }
        if std::env::args().nth(2).as_deref() == Some("app") {
            return crate::config_migrate::app::cli(&std::env::args().skip(3).collect::<Vec<_>>());
        }
        return crate::config_migrate::cli();
    }
    match parse_args() {
        Ok(args) => crate::commands::run(&args),
        Err(error) => {
            eprintln!("{error}");
            ExitCode::from(2)
        }
    }
}
