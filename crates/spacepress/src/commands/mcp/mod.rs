//! `spacepress mcp [dir] [--allow-remote-read] [--allow-writes]`: the project in `dir` for a coding
//! agent, as a Model Context Protocol server on stdin and stdout.
//!
//! This is the development-time half. The running site serves its own data and actions as a second
//! MCP server (`/wp-json/mcp/spacepress-<app>`, docs/agents.md); this one answers about the project's
//! source: its schema, the compiler's diagnostics, SpacePress's documentation, the command table and the
//! local dev site.
//!
//! The wire format is the specification's stdio transport: one JSON-RPC 2.0 message per line, and
//! nothing else on stdout. Two revisions of the protocol are served, chosen by how the client opens:
//!
//! - 2026-07-28: no handshake. Every request names its protocol version and the client's
//!   capabilities in `params._meta`, and `server/discover` answers what this server supports.
//! - 2025-11-25 and 2025-06-18: the `initialize` handshake, then requests without that metadata.
//!
//! A request that does neither is refused, so no method is ever answered under a guessed revision.
//!
//! Requests are answered one at a time, in the order they arrive, by one thread. The thread that
//! reads stdin only queues them and acts on `notifications/cancelled`: a request still queued is
//! dropped, and the one being answered has the command it is waiting on stopped. A cancelled request
//! gets no response. When stdin closes, the requests already received are answered and the server
//! exits, so `printf … | spacepress mcp` works; nothing it runs outlives the timeout.
//!
//! The compiler runs in a process of its own for each `schema` and `check` (`spacepress mcp <dir>
//! --compile`, an internal form of this command): a long-lived server then keeps nothing from one
//! compile to the next, and a compile is bounded and cancellable like any other command.

mod child;
mod docs;
mod tools;

use serde_json::{Map, Value, json};
use std::collections::HashSet;
use std::io::{BufRead, Write};
use std::path::PathBuf;
use std::process::ExitCode;
use std::sync::{Arc, Mutex, mpsc};
use std::time::Duration;

/// Revisions without a handshake, newest first.
const MODERN: &[&str] = &["2026-07-28"];
/// Revisions that open with `initialize`, newest first. 2025-03-26 is not served: it obliges a
/// server to accept JSON-RPC batches. A client that asks for it, or for anything older, is answered
/// with the newest of these and decides for itself, as the lifecycle rules say.
const LEGACY: &[&str] = &["2025-11-25", "2025-06-18"];

const PROTOCOL_VERSION: &str = "io.modelcontextprotocol/protocolVersion";
const CLIENT_CAPABILITIES: &str = "io.modelcontextprotocol/clientCapabilities";
const SERVER_INFO: &str = "io.modelcontextprotocol/serverInfo";
const SUBSCRIPTION_ID: &str = "io.modelcontextprotocol/subscriptionId";

// JSON-RPC 2.0, and the codes the specification adds.
const PARSE_ERROR: i64 = -32700;
const INVALID_REQUEST: i64 = -32600;
const METHOD_NOT_FOUND: i64 = -32601;
const INVALID_PARAMS: i64 = -32602;
const INTERNAL_ERROR: i64 = -32603;
const UNSUPPORTED_PROTOCOL_VERSION: i64 = -32022;
/// "Resource not found" through 2025-11-25; later revisions answer `INVALID_PARAMS`.
const LEGACY_RESOURCE_NOT_FOUND: i64 = -32002;

/// The tool list, the documentation and what `server/discover` says never change while the process
/// runs, so a client may keep them this long.
const FIXED_TTL_MS: u64 = 3_600_000;

const USAGE: &str = "usage: spacepress mcp [dir] [--allow-remote-read] [--allow-writes]";
/// Seconds a command run for a tool may take: `SP_MCP_TIMEOUT` overrides it.
const TIMEOUT: u64 = 120;

pub(crate) struct Options {
    /// The project directory, canonical.
    pub(crate) project: PathBuf,
    /// `query` may read a site other than this project's dev site.
    pub(crate) allow_remote_read: bool,
    /// The write tools are listed, for this project's dev site only.
    pub(crate) allow_writes: bool,
    /// How long a command run for a tool may take before it is stopped.
    timeout: Duration,
    flight: Arc<Mutex<Flight>>,
}

/// What the thread reading stdin needs to know to act on a cancellation.
#[derive(Default)]
struct Flight {
    /// Requests received and not answered yet, by id.
    pending: HashSet<String>,
    /// Those the client cancelled: none of them is answered.
    cancelled: HashSet<String>,
    /// The one being answered,
    current: Option<String>,
    /// the command it is waiting on,
    child: Option<u32>,
    /// and whether that command is to stop (as soon as it starts, if it has not).
    stop: bool,
}

/// A request's id as a key: `1` and `"1"` are different ids.
fn request_key(message: &Value) -> Option<String> {
    let id = message
        .get("id")
        .filter(|id| id.is_string() || id.is_i64() || id.is_u64())?;
    message.get("method")?.as_str().map(|_| id.to_string())
}

struct RpcError {
    code: i64,
    message: String,
    data: Option<Value>,
}

impl RpcError {
    fn new(code: i64, message: impl Into<String>) -> Self {
        RpcError {
            code,
            message: message.into(),
            data: None,
        }
    }

    fn data(mut self, data: Value) -> Self {
        self.data = Some(data);
        self
    }
}

#[derive(Clone, Copy, PartialEq)]
enum Era {
    Modern,
    Legacy,
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    let usage = |message: String| {
        eprintln!("{message}\n{USAGE}");
        ExitCode::from(2)
    };
    let (mut project, mut allow_remote_read, mut allow_writes, mut compile) = (None, false, false, false);
    for arg in args {
        match arg.as_str() {
            "--allow-remote-read" => allow_remote_read = true,
            "--allow-writes" => allow_writes = true,
            // The server's own way to compile in a separate process; not an author's option.
            "--compile" => compile = true,
            other if other.starts_with('-') => return usage(format!("unknown option {other} for spacepress mcp")),
            other if project.is_some() => {
                return usage(format!("spacepress mcp takes one project directory, not also {other}"));
            }
            other => project = Some(PathBuf::from(other)),
        }
    }
    let project = project.unwrap_or_else(|| PathBuf::from("."));
    let project = match project.canonicalize() {
        Ok(canonical) if canonical.is_dir() => canonical,
        Ok(_) => return usage(format!("{} is not a directory", project.display())),
        Err(error) => return usage(format!("{}: {error}", project.display())),
    };
    if compile {
        println!("{}", tools::compiled(&project));
        return ExitCode::SUCCESS;
    }
    let timeout = match std::env::var("SP_MCP_TIMEOUT") {
        Err(_) => TIMEOUT,
        Ok(seconds) => match seconds.parse::<u64>() {
            Ok(seconds) if seconds > 0 => seconds,
            _ => return usage(format!("SP_MCP_TIMEOUT is a number of seconds, not {seconds:?}")),
        },
    };
    let flight = Arc::new(Mutex::new(Flight::default()));
    let mut server = Server {
        options: Options {
            project,
            allow_remote_read,
            allow_writes,
            timeout: Duration::from_secs(timeout),
            flight: flight.clone(),
        },
        initialized: false,
    };
    // `None` is a line that is not JSON.
    let (queue, queued) = mpsc::channel::<Option<Value>>();
    let worker = std::thread::spawn(move || {
        let mut stdout = std::io::stdout().lock();
        for message in queued {
            let key = message.as_ref().and_then(request_key);
            if let Some(key) = &key {
                let mut flight = server.options.flight.lock().expect("flight");
                // Cancelled while it waited its turn: never started, never answered.
                if flight.cancelled.remove(key) {
                    flight.pending.remove(key);
                    continue;
                }
                flight.current = Some(key.clone());
                flight.stop = false;
            }
            let answers = server.receive(message);
            if let Some(key) = &key {
                let mut flight = server.options.flight.lock().expect("flight");
                flight.current = None;
                flight.pending.remove(key);
                if flight.cancelled.remove(key) {
                    continue;
                }
            }
            for answer in answers {
                // A message is one line: serde_json escapes every newline inside a string.
                if writeln!(stdout, "{answer}").and_then(|()| stdout.flush()).is_err() {
                    return;
                }
            }
        }
    });
    let mut input = std::io::stdin().lock();
    let mut line = Vec::new();
    loop {
        line.clear();
        // End of input, or a failed read: the client is gone.
        if !input.read_until(b'\n', &mut line).is_ok_and(|read| read > 0) {
            break;
        }
        let text = line.trim_ascii();
        if text.is_empty() {
            continue;
        }
        // Bytes that are not UTF-8 are not JSON either: answered as a parse error, like any other.
        let message = serde_json::from_slice::<Value>(text).ok();
        if let Some(message) = &message {
            let mut flight = flight.lock().expect("flight");
            if message["method"] == "notifications/cancelled" && message.get("id").is_none() {
                let key = message["params"]["requestId"].to_string();
                if flight.pending.contains(&key) {
                    if flight.current.as_ref() == Some(&key) {
                        flight.stop = true;
                        if let Some(pid) = flight.child {
                            child::stop(pid);
                        }
                    }
                    flight.cancelled.insert(key);
                }
                continue;
            }
            if let Some(key) = request_key(message) {
                flight.pending.insert(key);
            }
        }
        if queue.send(message).is_err() {
            break;
        }
    }
    // The requests already received are answered before the server exits.
    drop(queue);
    let _ = worker.join();
    ExitCode::SUCCESS
}

struct Server {
    options: Options,
    /// A client opened with `initialize`: its later requests carry no per-request metadata.
    initialized: bool,
}

/// Runs one request's work. A compiler bug must not take the server down with it: the panic's
/// message goes to stderr, the client gets an error for this request, and the next one is answered
/// as usual.
fn contained<T>(method: &str, work: impl FnOnce() -> Result<T, RpcError>) -> Result<T, RpcError> {
    std::panic::catch_unwind(std::panic::AssertUnwindSafe(work)).unwrap_or_else(|_| {
        Err(RpcError::new(
            INTERNAL_ERROR,
            format!("Internal error while answering {method}"),
        ))
    })
}

fn failure(id: Option<&Value>, error: RpcError) -> Value {
    let mut body = json!({ "code": error.code, "message": error.message });
    if let Some(data) = error.data {
        body["data"] = data;
    }
    let mut message = json!({ "jsonrpc": "2.0", "error": body });
    // An id that could not be read is left out. 2025-11-25 and later make `id` optional on an error
    // for this case; 2025-06-18 requires one and forbids `null`, so it has no valid answer, and
    // leaving it out is what JSON-RPC's own `null` means.
    if let Some(id) = id {
        message["id"] = id.clone();
    }
    message
}

impl Server {
    fn info() -> Value {
        json!({ "name": "spacepress", "title": "SpacePress", "version": env!("CARGO_PKG_VERSION") })
    }

    fn capabilities() -> Value {
        // No `listChanged` and no `subscribe`: neither list changes while the process runs.
        json!({ "tools": {}, "resources": {} })
    }

    fn instructions(&self) -> String {
        format!(
            "Development-time tools for the SpacePress project in {}. Call `schema` before changing kinds, actions or pages, \
             `check` after every edit, and `docs_search` before guessing an API: the documentation is the one this \
             compiler was built with. `query` reads the local dev site. The running site's own data and actions are a \
             different MCP server, at /wp-json/mcp/spacepress-<app> on the site.",
            self.options.project.display()
        )
    }

    /// One message from the client (`None`: a line that was not JSON): the messages to write back,
    /// none for a notification.
    fn receive(&mut self, message: Option<Value>) -> Vec<Value> {
        let Some(message) = message else {
            return vec![failure(None, RpcError::new(PARSE_ERROR, "Parse error: not JSON"))];
        };
        let invalid = |id: Option<&Value>, what: &str| {
            vec![failure(
                id,
                RpcError::new(INVALID_REQUEST, format!("Invalid request: {what}")),
            )]
        };
        let Some(request) = message.as_object() else {
            return invalid(
                None,
                if message.is_array() {
                    "JSON-RPC batches are not part of the protocol revisions this server speaks"
                } else {
                    "a message is a JSON object"
                },
            );
        };
        let id = request.get("id");
        if id.is_some_and(|id| !(id.is_string() || id.is_i64() || id.is_u64())) {
            return invalid(None, "id is a string or an integer");
        }
        let method = match request.get("method") {
            Some(Value::String(method)) => method.as_str(),
            Some(_) => return invalid(id, "method is a string"),
            // A response. This server sends no requests, so there is nothing it answers.
            None if request.contains_key("result") || request.contains_key("error") => return Vec::new(),
            None => return invalid(id, "a message has a method"),
        };
        if request.get("jsonrpc").and_then(Value::as_str) != Some("2.0") {
            return match id {
                Some(_) => invalid(id, "jsonrpc is \"2.0\""),
                None => Vec::new(),
            };
        }
        let Some(id) = id else {
            // A notification is never answered. `notifications/initialized` needs nothing, and the
            // thread that reads stdin has already acted on `notifications/cancelled`.
            return Vec::new();
        };
        let empty = Map::new();
        let params = match request.get("params") {
            None => &empty,
            Some(Value::Object(params)) => params,
            Some(_) => {
                return vec![failure(
                    Some(id),
                    RpcError::new(INVALID_PARAMS, "Invalid params: params is an object"),
                )];
            }
        };
        match contained(method, || self.request(id, method, params)) {
            Ok(messages) => messages,
            Err(error) => vec![failure(Some(id), error)],
        }
    }

    /// The revision a request is made under, from its metadata or the handshake before it.
    fn era(&self, method: &str, params: &Map<String, Value>) -> Result<Era, RpcError> {
        let meta = params.get("_meta").and_then(Value::as_object);
        if let Some(requested) = meta.and_then(|meta| meta.get(PROTOCOL_VERSION)) {
            let supported = requested.as_str().is_some_and(|version| MODERN.contains(&version));
            if !supported {
                return Err(
                    RpcError::new(UNSUPPORTED_PROTOCOL_VERSION, "Unsupported protocol version")
                        .data(json!({ "supported": MODERN, "requested": requested })),
                );
            }
            if !meta.is_some_and(|meta| meta.get(CLIENT_CAPABILITIES).is_some_and(Value::is_object)) {
                return Err(RpcError::new(
                    INVALID_PARAMS,
                    format!("Invalid params: params._meta needs {CLIENT_CAPABILITIES}"),
                ));
            }
            return Ok(Era::Modern);
        }
        // `ping` may come before the handshake completes.
        if self.initialized || matches!(method, "initialize" | "ping") {
            return Ok(Era::Legacy);
        }
        Err(RpcError::new(
            INVALID_PARAMS,
            format!(
                "Invalid params: this request names no protocol version. Send {PROTOCOL_VERSION} in params._meta ({}), or open with initialize ({})",
                MODERN.join(", "),
                LEGACY.join(", ")
            ),
        )
        .data(json!({ "supported": ([MODERN, LEGACY].concat()) })))
    }

    fn request(&mut self, id: &Value, method: &str, params: &Map<String, Value>) -> Result<Vec<Value>, RpcError> {
        let era = self.era(method, params)?;
        let done = |mut result: Value, cacheable: bool| {
            if era == Era::Modern {
                result["resultType"] = json!("complete");
                result["_meta"] = json!({ SERVER_INFO: Self::info() });
                if cacheable {
                    result["ttlMs"] = json!(FIXED_TTL_MS);
                    result["cacheScope"] = json!("public");
                }
            }
            Ok(vec![json!({ "jsonrpc": "2.0", "id": id, "result": result })])
        };
        // The lists fit one page, so no cursor is ever handed out and none is valid.
        let one_page = || match params.get("cursor") {
            None | Some(Value::Null) => Ok(()),
            Some(_) => Err(RpcError::new(INVALID_PARAMS, "Invalid params: unknown cursor")),
        };
        match (era, method) {
            (Era::Legacy, "initialize") => {
                let requested = params
                    .get("protocolVersion")
                    .and_then(Value::as_str)
                    .ok_or_else(|| RpcError::new(INVALID_PARAMS, "Invalid params: initialize needs protocolVersion"))?;
                // The requested revision when this server speaks it, otherwise its newest with a handshake.
                let version = LEGACY.iter().find(|v| **v == requested).unwrap_or(&LEGACY[0]);
                self.initialized = true;
                done(
                    json!({
                        "protocolVersion": version,
                        "capabilities": Self::capabilities(),
                        "serverInfo": Self::info(),
                        "instructions": self.instructions(),
                    }),
                    false,
                )
            }
            (Era::Legacy, "ping") => done(json!({}), false),
            (Era::Modern, "server/discover") => done(
                json!({
                    "supportedVersions": MODERN,
                    "capabilities": Self::capabilities(),
                    "instructions": self.instructions(),
                }),
                true,
            ),
            (Era::Modern, "subscriptions/listen") => {
                if !params.get("notifications").is_some_and(Value::is_object) {
                    return Err(RpcError::new(
                        INVALID_PARAMS,
                        "Invalid params: subscriptions/listen needs notifications",
                    ));
                }
                // Nothing this server lists ever changes, so the stream it opens honours no
                // notification type and stays silent until the client cancels it or leaves.
                Ok(vec![json!({
                    "jsonrpc": "2.0",
                    "method": "notifications/subscriptions/acknowledged",
                    "params": { "_meta": { SUBSCRIPTION_ID: id }, "notifications": {} },
                })])
            }
            (_, "tools/list") => {
                one_page()?;
                done(json!({ "tools": tools::list(&self.options) }), true)
            }
            (_, "tools/call") => {
                let name = params
                    .get("name")
                    .and_then(Value::as_str)
                    .ok_or_else(|| RpcError::new(INVALID_PARAMS, "Invalid params: tools/call needs name"))?;
                let arguments = match params.get("arguments") {
                    None | Some(Value::Null) => Map::new(),
                    Some(Value::Object(arguments)) => arguments.clone(),
                    Some(_) => return Err(RpcError::new(INVALID_PARAMS, "Invalid params: arguments is an object")),
                };
                let result = tools::call(&self.options, name, &arguments)?;
                done(result, false)
            }
            (_, "resources/list") => {
                one_page()?;
                done(json!({ "resources": docs::pages() }), true)
            }
            (_, "resources/templates/list") => {
                one_page()?;
                done(json!({ "resourceTemplates": [] }), true)
            }
            (_, "resources/read") => {
                let uri = params
                    .get("uri")
                    .and_then(Value::as_str)
                    .ok_or_else(|| RpcError::new(INVALID_PARAMS, "Invalid params: resources/read needs uri"))?;
                let Some((path, text)) = docs::find(uri) else {
                    let code = if era == Era::Modern {
                        INVALID_PARAMS
                    } else {
                        LEGACY_RESOURCE_NOT_FOUND
                    };
                    return Err(RpcError::new(code, "Resource not found").data(json!({ "uri": uri })));
                };
                done(
                    json!({ "contents": [{ "uri": docs::uri(path), "mimeType": "text/markdown", "text": text }] }),
                    true,
                )
            }
            _ => Err(RpcError::new(METHOD_NOT_FOUND, format!("Method not found: {method}"))),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::{INTERNAL_ERROR, RpcError, contained};

    #[test]
    fn a_panic_while_answering_becomes_an_internal_error_for_that_request() {
        let answered: Result<(), RpcError> = contained("tools/call", || panic!("a compiler bug"));
        let Err(error) = answered else {
            panic!("an error, not a result")
        };
        assert_eq!(error.code, INTERNAL_ERROR);
        assert_eq!(error.message, "Internal error while answering tools/call");
        // The next request is answered as usual.
        assert_eq!(contained("ping", || Ok(1)).ok(), Some(1));
    }
}
