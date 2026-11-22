//! The tools `typedpress mcp` lists. Each is something the binary already does: the compiler behind
//! `typedpress check`, the command table behind `typedpress help`, the dev site behind `typedpress dev status`, and
//! the site commands (`typedpress find`, `typedpress action`, `typedpress insert`, `typedpress update`).

use super::{INVALID_PARAMS, Options, RpcError, child, docs};
use crate::commands::{build, describe, dev};
use serde_json::{Map, Value, json};
use std::process::Command;

/// Rows `query` returns when the caller names no limit, and the most it returns.
const DEFAULT_ROWS: u64 = 20;
const MOST_ROWS: u64 = 500;

type Arguments = Map<String, Value>;

/// The answer to a tool call that ran: the JSON, and the same JSON as text for a client that reads
/// only text (the specification asks for both).
fn ok(value: Value) -> Value {
    json!({ "content": [{ "type": "text", "text": value.to_string() }], "structuredContent": value, "isError": false })
}

/// A failure the caller can act on (a wrong argument, a project that does not compile, a site that
/// is not there). The specification reports these in the result, not as a protocol error.
fn failed(message: impl Into<String>) -> Value {
    json!({ "content": [{ "type": "text", "text": message.into() }], "isError": true })
}

fn object(properties: Value, required: &[&str]) -> Value {
    json!({ "type": "object", "properties": properties, "required": required, "additionalProperties": false })
}

fn tool(name: &str, title: &str, description: &str, input: Value, annotations: Value) -> Value {
    json!({ "name": name, "title": title, "description": description, "inputSchema": input, "annotations": annotations })
}

/// The tools, in one fixed order. The write tools are listed only under `--allow-writes`.
pub(super) fn list(options: &Options) -> Vec<Value> {
    let read = json!({ "readOnlyHint": true, "openWorldHint": false });
    let kind = json!({ "type": "string", "description": "A kind's name as `schema` lists it." });
    let mut tools = vec![
        tool(
            "schema",
            "Project schema",
            "This project's kinds with their fields, access rules, relations and hooks; its globals, actions, jobs, \
             pages and HTTP routes, file buckets, roles, the Abilities the site registers and its endpoints. Compiled \
             from the source as `typedpress check` compiles it. Pass `kind` for one kind only.",
            object(json!({ "kind": kind }), &[]),
            read.clone(),
        ),
        tool(
            "check",
            "Compile and report problems",
            "Compiles the project and returns the `typedpress-diagnostics` envelope `typedpress check --json` prints: `ok`, and \
             each diagnostic's code, message, help and source location. Writes nothing. Run it after every edit.",
            object(json!({}), &[]),
            read.clone(),
        ),
        tool(
            "docs_search",
            "Search TypedPress's documentation",
            "Searches the documentation this compiler was built with and returns matching sections with their page \
             path, heading and a snippet. Read a result with `docs_read`.",
            object(
                json!({
                    "query": { "type": "string", "description": "Words to look for, for example `access rules owner`." },
                    "limit": { "type": "integer", "minimum": 1, "maximum": 25, "description": "Results to return; 8 by default." },
                }),
                &["query"],
            ),
            read.clone(),
        ),
        tool(
            "docs_read",
            "Read a documentation page",
            "Reads one page of TypedPress's documentation by the path `docs_search` returned, or one section of it by \
             heading. A long page read whole answers with its opening and its list of sections.",
            object(
                json!({
                    "path": { "type": "string", "description": "For example `docs/config-api.md`." },
                    "section": { "type": "string", "description": "A heading on the page; its subsections are included." },
                }),
                &["path"],
            ),
            read.clone(),
        ),
        tool(
            "commands",
            "The typedpress command table",
            "Every `typedpress` command with its arguments and a one-line summary, as `typedpress help` prints them. `build` \
             commands run on the project's files; `site` commands run inside WordPress as `wp typedpress <command>`.",
            object(json!({}), &[]),
            read.clone(),
        ),
        tool(
            "dev_status",
            "The local dev site",
            "Whether this project has a local WordPress dev site, whether it is running, its URL and port, and \
             whether a `typedpress dev` process is rebuilding it on save (`watching`, `pid`, `log`). `error` is that \
             process's last build when it failed, with the compiler's diagnostics; fix the source and it rebuilds. `typedpress dev --detach` \
             starts it in the background; `typedpress dev stop` stops it and keeps its data.",
            object(json!({}), &[]),
            read.clone(),
        ),
        tool(
            "query",
            "Read records",
            &format!(
                "Reads a kind's records from the local dev site with `typedpress find`, as the trusted command line: no \
                 access rules, drafts included. Returns {DEFAULT_ROWS} rows unless `limit` says otherwise, at most \
                 {MOST_ROWS}. Like every request to the site, it first publishes scheduled posts that are due.{}",
                if options.allow_remote_read {
                    " `site` reads another site: a project directory, a deployment workspace or a WP-CLI @alias."
                } else {
                    ""
                }
            ),
            object(
                {
                    let mut properties = json!({
                        "kind": kind,
                        "where": { "type": "object", "description": "A filter: `{ field: value }` or `{ field: { gt: 1 } }`, with `OR`, `AND` and `NOT`." },
                        "orderBy": { "type": "object", "description": "`{ field: \"asc\" | \"desc\" }`." },
                        "limit": { "type": "integer", "minimum": 1, "maximum": MOST_ROWS },
                        "offset": { "type": "integer", "minimum": 0 },
                        "columns": { "type": "object", "description": "`{ field: true }` for each field to return." },
                        "with": { "type": "object", "description": "`{ relation: true }` for each relation to load." },
                    });
                    if options.allow_remote_read {
                        properties["site"] =
                            json!({ "type": "string", "description": "Default: this project's dev site." });
                    }
                    properties
                },
                &["kind"],
            ),
            if options.allow_remote_read {
                json!({ "readOnlyHint": true })
            } else {
                read
            },
        ),
    ];
    if options.allow_writes {
        let write = |destructive: bool| json!({ "readOnlyHint": false, "destructiveHint": destructive, "openWorldHint": false });
        tools.extend([
            tool(
                "run_action",
                "Run an action",
                "Runs one of the project's actions on the local dev site with `typedpress action`, as the trusted command line.",
                object(
                    json!({
                        "name": { "type": "string", "description": "An action's name as `schema` lists it." },
                        "input": { "type": "object", "description": "The action's input." },
                    }),
                    &["name"],
                ),
                write(true),
            ),
            tool(
                "insert",
                "Insert records",
                "Inserts records of a kind into the local dev site with `typedpress insert`, as the trusted command line.",
                object(
                    json!({
                        "kind": kind.clone(),
                        "rows": { "type": "array", "items": { "type": "object" }, "minItems": 1, "description": "One object per record." },
                    }),
                    &["kind", "rows"],
                ),
                write(false),
            ),
            tool(
                "update",
                "Update records",
                "Updates the records of a kind that match `where` on the local dev site with `typedpress update`, as the trusted command line.",
                object(
                    json!({
                        "kind": kind.clone(),
                        "where": { "type": "object", "description": "Which records; a filter as `query` takes it." },
                        "set": { "type": "object", "description": "The fields to change." },
                    }),
                    &["kind", "where", "set"],
                ),
                write(true),
            ),
        ]);
    }
    tools
}

fn text<'a>(arguments: &'a Arguments, name: &str) -> Result<Option<&'a str>, String> {
    match arguments.get(name) {
        None | Some(Value::Null) => Ok(None),
        Some(Value::String(value)) if !value.trim().is_empty() => Ok(Some(value)),
        Some(_) => Err(format!("`{name}` is a non-empty string")),
    }
}

fn required<'a>(arguments: &'a Arguments, name: &str) -> Result<&'a str, String> {
    text(arguments, name)?.ok_or_else(|| format!("`{name}` is required"))
}

fn object_argument<'a>(arguments: &'a Arguments, name: &str) -> Result<Option<&'a Value>, String> {
    match arguments.get(name) {
        None | Some(Value::Null) => Ok(None),
        Some(value @ Value::Object(_)) => Ok(Some(value)),
        Some(_) => Err(format!("`{name}` is an object")),
    }
}

/// A kind's or action's name on its way to a command line. WP-CLI reads any argument that starts
/// with `-` as one of its own options (`--exec`, `--require`), so a name is letters, digits, `_`,
/// `.` and `-`, and starts with a letter, a digit or `_`.
fn name_argument<'a>(arguments: &'a Arguments, name: &str) -> Result<&'a str, String> {
    let value = required(arguments, name)?;
    let plain = value
        .chars()
        .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-'))
        && value.starts_with(|c: char| c.is_ascii_alphanumeric() || c == '_');
    if plain {
        Ok(value)
    } else {
        Err(format!("`{name}` is a name as `schema` lists it, not {value:?}"))
    }
}

/// Refuses an argument the tool does not take: a misspelt `where` must not read as "no filter".
fn known_arguments(options: &Options, name: &str, arguments: &Arguments) -> Result<(), String> {
    let tools = list(options);
    let Some(properties) = tools
        .iter()
        .find(|tool| tool["name"] == name)
        .and_then(|tool| tool["inputSchema"]["properties"].as_object())
    else {
        return Ok(());
    };
    let Some(unknown) = arguments.keys().find(|key| !properties.contains_key(*key)) else {
        return Ok(());
    };
    let takes: Vec<&str> = properties.keys().map(String::as_str).collect();
    Err(if takes.is_empty() {
        format!("{name} takes no arguments, and was given `{unknown}`")
    } else {
        format!("{name} takes {}, not `{unknown}`", takes.join(", "))
    })
}

/// The project has a dev site: `typedpress dev` wrote its marker (scripts/dev/studio.mjs).
fn dev_site(options: &Options) -> Result<(), String> {
    if dev::created(&options.project) {
        Ok(())
    } else {
        Err("this project has no dev site yet; start one with `typedpress dev` (`typedpress dev --no-watch` starts it and returns)".into())
    }
}

/// `typedpress mcp <dir> --compile`: the project compiled as `typedpress check` compiles it, as one JSON
/// document: the diagnostics `envelope`, and the `schema` description when there are no
/// diagnostics. It runs in a process of its own (see [`compile`]).
pub(super) fn compiled(project: &std::path::Path) -> Value {
    let (envelope, built) = build::check(project, build::frozen(false));
    json!({ "envelope": envelope, "schema": built.map(|built| describe::project(&built.schema)) })
}

/// Why a command run for a tool gave no answer, as the tool's result.
fn not_run(what: &str, ran: child::Ran, options: &Options) -> Value {
    match ran {
        child::Ran::TimedOut => failed(format!(
            "{what} took longer than {} seconds and was stopped; TP_MCP_TIMEOUT sets the limit",
            options.timeout.as_secs()
        )),
        // Never sent: the client cancelled the request.
        child::Ran::Cancelled => failed(format!("{what} was cancelled")),
        child::Ran::Failed(error) => failed(format!("cannot run {what}: {error}")),
        child::Ran::Finished(_) => unreachable!("a finished command has an answer"),
    }
}

/// The project compiled in a separate process: the diagnostics envelope, and the description of its
/// schema when there are no diagnostics. A server that runs for hours then keeps nothing from one
/// compile to the next, and a compile is bounded and cancellable like the site commands.
fn compile(options: &Options) -> Result<(Value, Option<Value>), Value> {
    let mut command =
        Command::new(std::env::current_exe().map_err(|e| failed(format!("cannot run the compiler: {e}")))?);
    command.arg("mcp").arg(&options.project).arg("--compile");
    match child::run(options, command) {
        child::Ran::Finished(output) => {
            let answer = serde_json::from_slice::<Value>(&output.stdout)
                .ok()
                .filter(|answer| output.status.success() && answer["envelope"].is_object());
            match answer {
                Some(mut answer) => {
                    let schema = Some(answer["schema"].take()).filter(|schema| !schema.is_null());
                    Ok((answer["envelope"].take(), schema))
                }
                None => Err(failed(format!(
                    "the compiler stopped without an answer (exit {}):\n{}",
                    output
                        .status
                        .code()
                        .map_or("signal".to_string(), |code| code.to_string()),
                    String::from_utf8_lossy(&output.stderr).trim()
                ))),
            }
        }
        ran => Err(not_run("the compiler", ran, options)),
    }
}

/// A kind's storage name, which the site commands take, for the name the author gave it. A name the
/// project does not have (or a project that does not compile) is passed on for the site to judge.
fn storage_name(options: &Options, kind: &str) -> Result<String, Value> {
    let (_, schema) = compile(options)?;
    Ok(schema
        .and_then(|schema| schema["kinds"][kind]["path"].as_str().map(str::to_string))
        .unwrap_or_else(|| kind.to_string()))
}

/// The JSON document a site command printed: its last line that is one. A PHP notice or a Studio
/// banner printed before it does not turn a finished command into one that "did not answer".
fn document(stdout: &str) -> Option<Value> {
    stdout
        .lines()
        .rev()
        .find_map(|line| serde_json::from_str::<Value>(line.trim()).ok().filter(Value::is_object))
}

/// Runs `typedpress <args>` in the project, as an author would, and answers with what it printed. The
/// site commands print one JSON document: `{"data": …}`, or `{"error": …}` with a non-zero status.
fn site_command(options: &Options, args: &[String]) -> Value {
    let first = match std::env::current_exe() {
        Ok(first) => first,
        Err(error) => return failed(format!("cannot run typedpress {}: {error}", args[0])),
    };
    let mut command = Command::new(first);
    command.args(args).current_dir(&options.project);
    let output = match child::run(options, command) {
        child::Ran::Finished(output) => output,
        ran => return not_run(&format!("typedpress {}", args[0]), ran, options),
    };
    let stdout = String::from_utf8_lossy(&output.stdout);
    match document(&stdout) {
        Some(answer) if output.status.success() && answer.get("error").is_none() => ok(answer),
        Some(answer) => json!({
            "content": [{ "type": "text", "text": answer.to_string() }],
            "structuredContent": answer,
            "isError": true,
        }),
        // Not a document: the site could not be reached. What the command said is the explanation.
        None => {
            let stderr = String::from_utf8_lossy(&output.stderr);
            let said: Vec<&str> = stdout
                .lines()
                .chain(stderr.lines())
                .filter(|l| !l.trim().is_empty())
                .collect();
            let tail = said[said.len().saturating_sub(6)..].join("\n");
            failed(format!(
                "typedpress {} did not answer (exit {}):\n{tail}",
                args[0],
                output
                    .status
                    .code()
                    .map_or("signal".to_string(), |code| code.to_string())
            ))
        }
    }
}

fn query(options: &Options, arguments: &Arguments) -> Result<Value, String> {
    let kind = name_argument(arguments, "kind")?;
    // Without `site` the read is this project's dev site. Any `site` is another site as far as this
    // server can tell, so each one needs the flag.
    let site = text(arguments, "site")?;
    match site {
        None => {}
        Some(site) if !options.allow_remote_read => {
            return Err(format!(
                "query reads this project's dev site only, and was asked for {site}. Start the server as `typedpress mcp --allow-remote-read` to read another site."
            ));
        }
        // WP-CLI would read it as one of its own options.
        Some(site) if site.starts_with('-') => {
            return Err("`site` is a project directory, a deployment workspace or a WP-CLI @alias".into());
        }
        Some(_) => {}
    }
    known_arguments(options, "query", arguments)?;
    if site.is_none() {
        dev_site(options)?;
    }
    let mut find = Map::new();
    for name in ["where", "orderBy", "columns", "with"] {
        if let Some(value) = object_argument(arguments, name)? {
            find.insert(name.into(), value.clone());
        }
    }
    let count = |name: &str, least: u64| match arguments.get(name) {
        None | Some(Value::Null) => Ok(None),
        Some(value) => value
            .as_u64()
            .filter(|n| *n >= least)
            .map(Some)
            .ok_or_else(|| format!("`{name}` is an integer, {least} or more")),
    };
    let limit = count("limit", 1)?.unwrap_or(DEFAULT_ROWS);
    if limit > MOST_ROWS {
        return Err(format!("`limit` is at most {MOST_ROWS}; page with `offset` for more"));
    }
    find.insert("limit".into(), json!(limit));
    if let Some(offset) = count("offset", 0)? {
        find.insert("offset".into(), json!(offset));
    }
    let kind = match storage_name(options, kind) {
        Ok(kind) => kind,
        Err(answer) => return Ok(answer),
    };
    let mut args = vec!["find".to_string(), kind, Value::Object(find).to_string()];
    if let Some(site) = site {
        args.extend(["--site".to_string(), site.to_string()]);
    }
    Ok(site_command(options, &args))
}

fn write(options: &Options, name: &str, arguments: &Arguments) -> Result<Value, String> {
    if !options.allow_writes {
        return Err(format!(
            "{name} writes to the dev site, and this server was started read-only. Start it as `typedpress mcp --allow-writes` to allow writes to this project's dev site."
        ));
    }
    if arguments.contains_key("site") {
        return Err(format!(
            "{name} writes to this project's dev site and no other; it takes no `site`"
        ));
    }
    known_arguments(options, name, arguments)?;
    dev_site(options)?;
    let object = |name: &str| object_argument(arguments, name)?.ok_or_else(|| format!("`{name}` is required"));
    let kind =
        || -> Result<Result<String, Value>, String> { Ok(storage_name(options, name_argument(arguments, "kind")?)) };
    let args = match name {
        "run_action" => vec![
            "action".to_string(),
            name_argument(arguments, "name")?.to_string(),
            object_argument(arguments, "input")?.unwrap_or(&json!({})).to_string(),
        ],
        "insert" => {
            let rows = arguments
                .get("rows")
                .and_then(Value::as_array)
                .filter(|rows| !rows.is_empty() && rows.iter().all(Value::is_object))
                .ok_or("`rows` is a list of one or more objects")?;
            let kind = match kind()? {
                Ok(kind) => kind,
                Err(answer) => return Ok(answer),
            };
            vec!["insert".to_string(), kind, Value::Array(rows.clone()).to_string()]
        }
        _ => {
            let (filter, set) = (object("where")?.to_string(), object("set")?.to_string());
            let kind = match kind()? {
                Ok(kind) => kind,
                Err(answer) => return Ok(answer),
            };
            vec!["update".to_string(), kind, filter, set]
        }
    };
    // No --site: the write tools reach this project's dev site and no other.
    Ok(site_command(options, &args))
}

pub(super) fn call(options: &Options, name: &str, arguments: &Arguments) -> Result<Value, RpcError> {
    let result = match name {
        // These two check their arguments themselves, after the refusals that explain a flag.
        "query" => query(options, arguments),
        "run_action" | "insert" | "update" => write(options, name, arguments),
        "schema" | "check" | "docs_search" | "docs_read" | "commands" | "dev_status"
            if let Err(unknown) = known_arguments(options, name, arguments) =>
        {
            Err(unknown)
        }
        "schema" => (|| {
            let (envelope, schema) = match compile(options) {
                Ok(compiled) => compiled,
                Err(answer) => return Ok(answer),
            };
            let Some(mut schema) = schema else {
                return Ok(json!({
                    "content": [{ "type": "text", "text": format!("The project does not compile, so it has no schema yet. Fix these diagnostics first:\n{envelope}") }],
                    "structuredContent": envelope,
                    "isError": true,
                }));
            };
            if let Some(kind) = text(arguments, "kind")? {
                let Some(found) = schema["kinds"].get(kind).cloned() else {
                    let names: Vec<&str> = schema["kinds"]
                        .as_object()
                        .into_iter()
                        .flatten()
                        .map(|(k, _)| k.as_str())
                        .collect();
                    return Err(format!(
                        "no kind {kind}; this project's kinds are: {}",
                        names.join(", ")
                    ));
                };
                schema = json!({ "name": schema["name"], "kinds": { kind: found }, "roles": schema["roles"] });
            }
            Ok(ok(schema))
        })(),
        "check" => Ok(compile(options).map_or_else(|answer| answer, |(envelope, _)| ok(envelope))),
        "docs_search" => (|| {
            let query = required(arguments, "query")?;
            let limit = match arguments.get("limit") {
                None | Some(Value::Null) => 8,
                Some(limit) => limit
                    .as_u64()
                    .filter(|n| (1..=25).contains(n))
                    .ok_or("`limit` is an integer from 1 to 25")? as usize,
            };
            Ok(ok(json!({ "query": query, "results": docs::search(query, limit) })))
        })(),
        "docs_read" => (|| {
            Ok(ok(docs::read(
                required(arguments, "path")?,
                text(arguments, "section")?,
            )?))
        })(),
        "commands" => Ok(ok(
            serde_json::from_str(crate::cli::COMMANDS).expect("runtime/TypedPress/Cli/commands.json is JSON")
        )),
        // Studio's own status, for a site with no state file, is bounded and cancellable too.
        "dev_status" => dev::status_with(&options.project, |studio| match child::run(options, studio) {
            child::Ran::Finished(output) => Ok(output),
            ran => Err(
                not_run("the dev site's status (npx wp-studio)", ran, options)["content"][0]["text"]
                    .as_str()
                    .unwrap_or_default()
                    .to_string(),
            ),
        })
        .map(ok),
        _ => return Err(RpcError::new(INVALID_PARAMS, format!("Unknown tool: {name}"))),
    };
    Ok(result.unwrap_or_else(failed))
}
