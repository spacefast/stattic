//! The `spacepress` command line: one table (`runtime/SpacePress/Cli/commands.json`, also read by `wp spacepress help`)
//! lists every command. "build" commands run here; "site" commands forward to `wp spacepress <command>`
//! on the site `--site` picks, and `spacepress wp <args>` forwards any WP-CLI command
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
    /// `spacepress dev`: run in the background, or stay attached whatever is driving the command.
    pub(crate) detach: bool,
    pub(crate) foreground: bool,
    pub(crate) allow_destructive: bool,
    /// `spacepress dev --url` / `--host`: the address WordPress writes into its pages, and the
    /// address of this machine the dev process listens on. Checked by scripts/dev/studio.mjs.
    pub(crate) dev_url: Option<String>,
    pub(crate) dev_host: Option<String>,
    pub(crate) preserve_data: Option<PathBuf>,
    pub(crate) diagnostics_file: Option<PathBuf>,
    /// Refuse plugin grants the committed lock doesn't hold (also on whenever `CI` is set).
    pub(crate) frozen: bool,
    /// `spacepress check --fix`: apply auto-fixes for compile-time lint warnings.
    pub(crate) fix: bool,
    /// `spacepress doctor --links`: follow every link and anchor the site's pages make.
    pub(crate) links: bool,
}

pub(crate) const COMMANDS: &str = include_str!("../runtime/SpacePress/Cli/commands.json");

struct Entry {
    name: String,
    args: String,
    summary: String,
    group: &'static str,
}

fn table() -> Vec<Entry> {
    let table: Value = serde_json::from_str(COMMANDS).expect("runtime/SpacePress/Cli/commands.json is JSON");
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

/// `spacepress help`: the commands by group, one line each.
pub fn help() -> String {
    let entries = table();
    let width = entries.iter().map(|e| e.name.len()).max().unwrap_or(0);
    let mut out = String::from("usage: spacepress <command> [options]\n");
    for (group, title) in [
        ("build", "Build (runs here, on the project's files)"),
        (
            "site",
            "Site (runs as `wp spacepress <command>`; --site <dir | workspace | @alias>, default: this project's dev site)",
        ),
    ] {
        out.push_str(&format!("\n{title}:\n"));
        for e in entries.iter().filter(|e| e.group == group) {
            out.push_str(&format!("  {:width$}  {}\n", e.name, e.summary));
        }
    }
    out.push_str(
        "\nspacepress help <command> shows its arguments. Commands that talk to a site sign in with the credential\nspacepress login <site-url> stored; in CI, set SP_APP_PASSWORD=user:application-password or SP_TOKEN instead.\n",
    );
    out
}

/// `usage: first <name> <args>` for every table row whose name starts with `name`.
fn usage(name: &str) -> Option<String> {
    let lines: Vec<String> = table()
        .into_iter()
        .filter(|e| e.name == name || e.name.starts_with(&format!("{name} ")))
        .map(|e| {
            format!("usage: spacepress {} {}", e.name, e.args)
                .trim_end()
                .to_string()
        })
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
        detach: false,
        foreground: false,
        allow_destructive: false,
        dev_url: None,
        dev_host: None,
        preserve_data: None,
        diagnostics_file: None,
        frozen: false,
        fix: false,
        links: false,
    };
    let command = args.command.clone();
    let fail = |message: String| usage_error(&command, message);
    while let Some(a) = it.next() {
        match a.as_str() {
            "stop" | "status" if command == "dev" && args.rest.is_empty() => args.rest.push(a),
            "--no-watch" if command == "dev" => args.no_watch = true,
            "--detach" if command == "dev" && args.rest.is_empty() => args.detach = true,
            "--foreground" if command == "dev" && args.rest.is_empty() => args.foreground = true,
            "--json" if command == "dev" && args.rest.first().is_some_and(|mode| mode == "status") => args.json = true,
            "--allow-destructive" if command == "dev" => args.allow_destructive = true,
            "--url" if command == "dev" && args.rest.is_empty() => {
                args.dev_url = Some(
                    it.next()
                        .ok_or_else(|| fail("--url needs an address such as https://box.example.ts.net:8443".into()))?,
                )
            }
            "--host" if command == "dev" && args.rest.is_empty() => {
                args.dev_host = Some(
                    it.next()
                        .ok_or_else(|| fail("--host needs an address of this machine, such as 10.0.0.24".into()))?,
                )
            }
            "--preserve-data" if command == "build" => {
                args.preserve_data = Some(PathBuf::from(
                    it.next()
                        .ok_or_else(|| fail("--preserve-data needs a snapshot path".into()))?,
                ));
            }
            // Internal to `spacepress dev` (scripts/dev/studio.mjs), so it is in no usage line and no
            // reference page: the build writes the compile's `spacepress-diagnostics` envelope to this
            // path, `ok` with none when it compiled, for the browser overlay and `dev status`.
            "--diagnostics-file" if command == "build" => {
                args.diagnostics_file = Some(PathBuf::from(
                    it.next()
                        .ok_or_else(|| fail("--diagnostics-file needs a path".into()))?,
                ));
            }
            "--site" if command == "doctor" => {
                args.site = Some(it.next().ok_or_else(|| fail("--site needs a URL".into()))?)
            }
            "--json" if matches!(command.as_str(), "doctor" | "check") => args.json = true,
            "--fix" if command == "check" => args.fix = true,
            "--links" if command == "doctor" => args.links = true,
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
            other if other.starts_with('-') => {
                return Err(fail(format!("unknown option {other} for spacepress {command}")));
            }
            other => args.project = PathBuf::from(other),
        }
    }
    Ok(args)
}

/// The first argument of `spacepress migrate` that is not a flag or `--site`'s value.
fn migrate_positional(rest: &[String]) -> Option<&str> {
    let mut args = rest.iter();
    while let Some(arg) = args.next() {
        if arg == "--site" {
            args.next();
        } else if !arg.starts_with('-') {
            return Some(arg);
        }
    }
    None
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
                    Some(text) if name == "add" => print!("{text}\n{}", crate::commands::add::modules()),
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
                usage_error("wp", "WP-CLI on a workspace is spacepress wp --site <workspace> <args>")
            );
            ExitCode::from(2)
        }
        "deploy" => crate::commands::deploy::adapter(&rest),
        "danger" => crate::commands::danger::cli(&rest),
        "add" => crate::commands::add::cli(&rest),
        "abilities" if rest.first().is_some_and(|sub| sub == "pull") => crate::commands::abilities::cli(&rest),
        "abilities" if rest.first().is_some_and(|sub| !sub.starts_with('-')) => {
            eprintln!(
                "{}",
                usage_error("abilities", format!("no command abilities {}", rest[0]))
            );
            ExitCode::from(2)
        }
        "styles" if rest.first().is_some_and(|sub| sub == "pull") => crate::commands::styles::cli(&rest),
        "styles" => {
            eprintln!(
                "{}",
                usage_error(
                    "styles",
                    format!("no command styles {}", rest.first().map_or("", String::as_str))
                )
            );
            ExitCode::from(2)
        }
        // `migrate` applies the app's own database migrations and takes flags only. A word after it
        // is an import request, which the separate importer answers.
        "migrate" if migrate_positional(&rest).is_some() => {
            eprintln!(
                "{}",
                usage_error(
                    "migrate",
                    format!(
                        "no command migrate {}: spacepress migrate applies this app's database migrations. To import a project, run spacepress-migrate --from <source> <operation>",
                        migrate_positional(&rest).unwrap_or_default()
                    )
                )
            );
            ExitCode::from(2)
        }
        "sync" => crate::commands::sync(&rest),
        "pull" => crate::commands::pull::cli(&rest),
        "plugin" => crate::commands::plugin::cli(&rest),
        "login" | "logout" => crate::commands::login::cli(&command, &rest),
        "push" => crate::commands::push::cli(&rest),
        "export" => crate::commands::export::cli(&rest),
        "mcp" => crate::commands::mcp::cli(&rest),
        "init" => crate::commands::init::cli(&rest),
        "wp" => {
            let mut rest = rest;
            exit(crate::commands::site::take_site(&mut rest).and_then(|site| {
                if rest.is_empty() {
                    return Err(usage_error("wp", "spacepress wp needs WP-CLI arguments"));
                }
                Ok(crate::commands::site::forward(site.as_deref(), &rest))
            }))
        }
        _ => match table().into_iter().find(|e| e.name == command) {
            Some(entry) if entry.group == "site" => {
                let mut rest = rest;
                exit(crate::commands::site::take_site(&mut rest).map(|site| {
                    let mut args = vec!["spacepress".to_string(), command.clone()];
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
