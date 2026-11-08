//! The `zero` command line: one table (`runtime/Zero/Cli/commands.json`, also read by `wp zero help`)
//! lists every command. "build" commands run here; "site" commands forward to `wp zero <command>`
//! on the site `--site` picks, and `zero wp <args>` forwards any WP-CLI command
//! (docs/decisions/2026-11-07-one-cli.md).

use serde_json::Value;
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
    pub(crate) json: bool,
    pub(crate) no_watch: bool,
    pub(crate) allow_destructive: bool,
    pub(crate) preserve_data: Option<PathBuf>,
    /// Refuse plugin grants the committed lock doesn't hold (also on whenever `CI` is set).
    pub(crate) frozen: bool,
}

const COMMANDS: &str = include_str!("../runtime/Zero/Cli/commands.json");

struct Entry {
    name: String,
    args: String,
    summary: String,
    group: &'static str,
}

fn table() -> Vec<Entry> {
    let table: Value = serde_json::from_str(COMMANDS).expect("runtime/Zero/Cli/commands.json is JSON");
    let mut entries = Vec::new();
    for group in ["build", "site"] {
        for row in table[group].as_array().expect("command group") {
            entries.push(Entry {
                name: row["name"].as_str().expect("command name").to_string(),
                args: row["args"].as_str().unwrap_or_default().to_string(),
                summary: row["summary"].as_str().unwrap_or_default().to_string(),
                group,
            });
        }
    }
    entries
}

/// `zero help`: the commands by group, one line each.
pub fn help() -> String {
    let entries = table();
    let width = entries.iter().map(|e| e.name.len()).max().unwrap_or(0);
    let mut out = String::from("usage: zero <command> [options]\n");
    for (group, title) in [
        ("build", "Build (runs here, on the project's files)"),
        (
            "site",
            "Site (runs as `wp zero <command>`; --site <dir | workspace | @alias>, default: this project's dev site)",
        ),
    ] {
        out.push_str(&format!("\n{title}:\n"));
        for e in entries.iter().filter(|e| e.group == group) {
            out.push_str(&format!("  {:width$}  {}\n", e.name, e.summary));
        }
    }
    out.push_str("\nzero help <command> shows its arguments. Credentials come from ZERO_APP_PASSWORD or ZERO_TOKEN.\n");
    out
}

/// `usage: zero <name> <args>` for every table row whose name starts with `name`.
fn usage(name: &str) -> Option<String> {
    let lines: Vec<String> = table()
        .into_iter()
        .filter(|e| e.name == name || e.name.starts_with(&format!("{name} ")))
        .map(|e| format!("usage: zero {} {}", e.name, e.args).trim_end().to_string())
        .collect();
    (!lines.is_empty()).then(|| lines.join("\n"))
}

fn usage_error(command: &str, message: impl std::fmt::Display) -> String {
    format!("{message}\n{}", usage(command).unwrap_or_else(help))
}

fn parse_args(command: String, mut it: impl Iterator<Item = String>) -> Result<Args, String> {
    let mut args = Args {
        command,
        project: PathBuf::from("."),
        out: None,
        port: 8787,
        quiet: false,
        rest: Vec::new(),
        site: None,
        json: false,
        no_watch: false,
        allow_destructive: false,
        preserve_data: None,
        frozen: false,
    };
    let command = args.command.clone();
    let fail = |message: String| usage_error(&command, message);
    while let Some(a) = it.next() {
        match a.as_str() {
            "stop" | "status" if command == "dev" && args.rest.is_empty() => args.rest.push(a),
            "--no-watch" if command == "dev" => args.no_watch = true,
            "--allow-destructive" if command == "dev" => args.allow_destructive = true,
            "--preserve-data" if command == "build" => {
                args.preserve_data = Some(PathBuf::from(
                    it.next()
                        .ok_or_else(|| fail("--preserve-data needs a snapshot path".into()))?,
                ));
            }
            "--site" if command == "doctor" => {
                args.site = Some(it.next().ok_or_else(|| fail("--site needs a URL".into()))?)
            }
            "--json" if matches!(command.as_str(), "doctor" | "check") => args.json = true,
            "--frozen" if matches!(command.as_str(), "build" | "check") => args.frozen = true,
            "--out" if command != "check" => {
                args.out = Some(PathBuf::from(
                    it.next().ok_or_else(|| fail("--out needs a directory".into()))?,
                ))
            }
            "--port" if command == "dev" => {
                args.port = it
                    .next()
                    .and_then(|p| p.parse().ok())
                    .filter(|p| *p != 0)
                    .ok_or_else(|| fail("--port needs a number between 1 and 65535".into()))?;
            }
            "--quiet" | "-q" if command == "build" => args.quiet = true,
            other if other.starts_with('-') => return Err(fail(format!("unknown option {other} for zero {command}"))),
            other => args.project = PathBuf::from(other),
        }
    }
    Ok(args)
}

fn exit(result: Result<ExitCode, String>) -> ExitCode {
    result.unwrap_or_else(|error| {
        eprintln!("{error}");
        ExitCode::from(2)
    })
}

pub fn main() -> ExitCode {
    let mut argv: Vec<String> = std::env::args().skip(1).collect();
    let Some(command) = argv.first().cloned() else {
        eprint!("{}", help());
        return ExitCode::from(2);
    };
    let rest = argv.split_off(1);
    match command.as_str() {
        "help" | "--help" | "-h" => {
            match rest.first() {
                None => print!("{}", help()),
                Some(name) => match usage(&rest.join(" ")).or_else(|| usage(name)) {
                    Some(text) => println!("{text}"),
                    None => {
                        eprint!("no command {name}\n{}", help());
                        return ExitCode::from(2);
                    }
                },
            }
            ExitCode::SUCCESS
        }
        // The hosted pipeline's internal steps; not author commands, so not in the table.
        "sources" => crate::bundle::sources_cli(&rest),
        "compile-sources" => crate::bundle::compile_cli(&rest),
        "compile-to-store" => crate::bundle::compile_to_store_cli(&rest),
        "deploy" if rest.first().is_some_and(|mode| mode == "wp") => {
            eprintln!(
                "{}",
                usage_error("wp", "WP-CLI on a workspace is zero wp --site <workspace> <args>")
            );
            ExitCode::from(2)
        }
        "deploy" => crate::commands::deploy::adapter(&rest),
        "abilities" if rest.first().is_some_and(|sub| sub == "pull") => crate::commands::abilities::cli(&rest),
        "abilities" if rest.first().is_some_and(|sub| !sub.starts_with('-')) => {
            eprintln!(
                "{}",
                usage_error("abilities", format!("no command abilities {}", rest[0]))
            );
            ExitCode::from(2)
        }
        "migrate" if rest.first().is_some_and(|sub| sub == "app") => crate::config_migrate::app::cli(&rest[1..]),
        "migrate" if rest.first().is_some_and(|sub| sub == "config") => crate::config_migrate::cli(),
        "migrate" if rest.first().is_some_and(|sub| !sub.starts_with('-')) => {
            eprintln!("{}", usage_error("migrate", format!("no command migrate {}", rest[0])));
            ExitCode::from(2)
        }
        "sync" => crate::commands::sync(&rest),
        "wp" => {
            let mut rest = rest;
            exit(crate::commands::site::take_site(&mut rest).and_then(|site| {
                if rest.is_empty() {
                    return Err(usage_error("wp", "zero wp needs WP-CLI arguments"));
                }
                Ok(crate::commands::site::forward(site.as_deref(), &rest))
            }))
        }
        _ => match table().into_iter().find(|e| e.name == command) {
            Some(entry) if entry.group == "site" => {
                let mut rest = rest;
                exit(crate::commands::site::take_site(&mut rest).map(|site| {
                    let mut args = vec!["zero".to_string(), command.clone()];
                    args.extend(rest);
                    crate::commands::site::forward(site.as_deref(), &args)
                }))
            }
            Some(_) => match parse_args(command, rest.into_iter()) {
                Ok(args) => crate::commands::run(&args),
                Err(error) => {
                    eprintln!("{error}");
                    ExitCode::from(2)
                }
            },
            None => {
                eprint!("no command {command}\n{}", help());
                ExitCode::from(2)
            }
        },
    }
}
