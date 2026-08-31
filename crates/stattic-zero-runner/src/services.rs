//! The platform-service broker: Gravatar, spam checking, and mail.
//!
//! One implementation serves both execution tiers. Zero calls it in-process
//! through a host function on the QuickJS context; the Functions relay spawns
//! it as a one-shot executor over stdio, the same shape the DB broker already
//! uses. Neither tier ships a second copy, so a tenant cannot observe a
//! difference in behaviour between them — which is the whole promise of the
//! shared client in `@spacefast/common/contracts/runtime-services`.
//!
//! Nothing here is reachable with a tenant-supplied URL. A frame names a
//! service and an operation; this module owns every upstream it can contact,
//! and [`SERVICE_UPSTREAM_HOSTS`] names them so the runtime's egress allowlist
//! can be built from that list rather than a second copy of it.
//!
//! Credentials arrive in the process environment under the `SPACEFAST_` prefix
//! the execution contract reserves, so tenant variables cannot shadow them, and
//! they never cross back into JavaScript in any form.

use std::cell::RefCell;
use std::io::Read;
use std::sync::OnceLock;
use std::time::Duration;

use serde::Deserialize;
use serde_json::{json, Map, Value};

use crate::artifacts::ExecutionMode;
use crate::db::BrokerRefusal;

/// Every host this module can reach. Exported for the runtime egress allowlist
/// to consume, so the set of upstreams is declared once.
pub const SERVICE_UPSTREAM_HOSTS: &[&str] =
    &["api.gravatar.com", "gravatar.com", "rest.akismet.com"];

const GRAVATAR_PROFILE_BASE: &str = "https://api.gravatar.com/v3/profiles/";
const AKISMET_BASE: &str = "https://rest.akismet.com/1.1/";

/// Akismet asks integrators to identify themselves in this exact shape.
const AKISMET_USER_AGENT: &str = "Spacefast/1.0 | Akismet/1.1";

/// A visitor is waiting behind every one of these calls, on a PHP-FPM worker
/// this space shares. A slow upstream must surface as a refusal quickly rather
/// than hold the slot until the request itself times out.
const SERVICE_HTTP_TIMEOUT: Duration = Duration::from_secs(5);

/// Upstream bodies are small — a profile document or the literal `true`. A
/// larger response means something other than the service we asked for.
const SERVICE_RESPONSE_MAX_BYTES: u64 = 256 * 1024;
const CONTENT_RESPONSE_MAX_BYTES: u64 = 2 * 1024 * 1024;

/// The frame limit. Generous enough for a long comment plus its metadata, and
/// far under what MySQL or the relay would accept.
pub(crate) const SERVICE_FRAME_MAX_BYTES: usize = 1024 * 1024;

thread_local! {
    static SERVICE_GRANT: RefCell<ServiceGrant> = const { RefCell::new(ServiceGrant::NONE) };
    static ZERO_EXECUTION_MODE: RefCell<Option<ExecutionMode>> = const { RefCell::new(None) };
}

/// Which services this invocation may reach.
///
/// The generated host only installs the clients a version declared, but that is
/// convenience, not the boundary: this check runs inside the broker, on the
/// frame itself, so an ungranted service is refused even if something upstream
/// of it were wrong.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub(crate) struct ServiceGrant {
    pub gravatar: bool,
    pub spam: bool,
    pub email: bool,
    pub content: bool,
    pub storage: bool,
}

impl ServiceGrant {
    pub(crate) const NONE: Self = Self {
        gravatar: false,
        spam: false,
        email: false,
        content: false,
        storage: false,
    };

    /// Parses the comma-separated wire grant the relay passes to the executor.
    /// Fails closed: an absent or unreadable grant reaches nothing.
    pub(crate) fn from_wire(raw: &str) -> Self {
        let granted = |name: &str| raw.split(',').any(|token| token.trim() == name);
        Self {
            gravatar: granted("gravatar.profile"),
            spam: granted("spam.check"),
            email: granted("email.send"),
            // The Functions tier already spells the storage grant this way;
            // one vocabulary across tiers rather than a second spelling here.
            storage: granted("storage.read"),
            content: granted("content.query"),
        }
    }

    fn permits(self, service: &str) -> bool {
        match service {
            "gravatar" => self.gravatar,
            "spam" => self.spam,
            "email" => self.email,
            "content" => self.content,
            "storage" => self.storage,
            _ => false,
        }
    }
}

pub(crate) fn set_grant(grant: ServiceGrant) {
    SERVICE_GRANT.with(|state| *state.borrow_mut() = grant);
}

pub(crate) fn set_zero_execution_mode(mode: Option<ExecutionMode>) {
    ZERO_EXECUTION_MODE.with(|state| *state.borrow_mut() = mode);
}

fn grant() -> ServiceGrant {
    SERVICE_GRANT.with(|state| *state.borrow())
}

#[derive(Debug, Deserialize)]
struct ServiceFrame {
    service: String,
    operation: String,
    #[serde(default)]
    payload: Map<String, Value>,
}

/// Per-invocation identity and credentials, read once from the environment.
///
/// The blog URL is the space's own canonical origin and is supplied by the
/// runtime, never by the caller: Akismet partitions reputation by it, so a
/// tenant that could name it could spend — or poison — another space's standing.
struct ServiceConfig {
    akismet_key: Option<String>,
    gravatar_key: Option<String>,
    blog_url: Option<String>,
    /// The addresses this space may send as, comma separated. Management state,
    /// not version content: rolling a version back must not roll back who a
    /// space has proven it owns.
    email_senders: Vec<String>,
    space_id: String,
    version_id: String,
    invocation_id: String,
    content_url: Option<String>,
    content_cookie: Option<String>,
    content_authorization: Option<String>,
}

impl ServiceConfig {
    fn from_env() -> Self {
        let value = |name: &str| {
            std::env::var(name)
                .ok()
                .map(|value| value.trim().to_string())
                .filter(|value| !value.is_empty())
        };
        Self {
            akismet_key: value("SPACEFAST_SERVICE_AKISMET_KEY"),
            gravatar_key: value("SPACEFAST_SERVICE_GRAVATAR_KEY"),
            blog_url: value("SPACEFAST_SERVICE_BLOG_URL"),
            email_senders: value("SPACEFAST_SERVICE_EMAIL_SENDERS")
                .map(|raw| {
                    raw.split(',')
                        .map(|entry| entry.trim().to_ascii_lowercase())
                        .filter(|entry| !entry.is_empty())
                        .collect()
                })
                .unwrap_or_default(),
            space_id: value("SPACEFAST_SERVICE_SPACE_ID").unwrap_or_default(),
            version_id: value("SPACEFAST_SERVICE_VERSION_ID").unwrap_or_default(),
            invocation_id: value("SPACEFAST_SERVICE_INVOCATION_ID").unwrap_or_default(),
            content_url: value("SPACEFAST_SERVICE_CONTENT_URL"),
            content_cookie: value("SPACEFAST_SERVICE_CONTENT_COOKIE"),
            content_authorization: value("SPACEFAST_SERVICE_CONTENT_AUTHORIZATION"),
        }
    }
}

/// One frame in, one JSON document out. Refusals are in-band and typed: the
/// caller is tenant code that must be able to branch on a stable code, not read
/// a transport status it never sees.
pub(crate) fn handle_service_frame(raw: &str) -> String {
    match execute_service_frame(raw) {
        Ok(result) => json!({ "ok": true, "result": result }).to_string(),
        Err(error) => error.refusal_json(),
    }
}

fn execute_service_frame(raw: &str) -> Result<Value, BrokerRefusal> {
    if raw.len() > SERVICE_FRAME_MAX_BYTES {
        return Err(BrokerRefusal::new(
            "service_payload_invalid",
            "The service frame is too large.",
        ));
    }
    let frame: ServiceFrame = serde_json::from_str(raw).map_err(|_| {
        BrokerRefusal::new("service_payload_invalid", "The service frame is not valid.")
    })?;
    if !grant().permits(&frame.service) {
        return Err(BrokerRefusal::new(
            "service_capability_denied",
            format!(
                "This version did not declare the {} capability.",
                frame.service
            ),
        ));
    }
    let read_only = ZERO_EXECUTION_MODE.with(|state| {
        state
            .borrow()
            .is_some_and(|mode| mode == ExecutionMode::Read)
    });
    if read_only
        && matches!(
            (frame.service.as_str(), frame.operation.as_str()),
            ("spam", "report_spam" | "report_ham")
                | ("email", "send")
                // Storage writes leave a mark outside the database, so a read
                // handler — replayable, and re-run for every subscription
                // refresh — is refused one before the transport is consulted.
                | ("storage", "put" | "delete")
        )
    {
        return Err(BrokerRefusal::new(
            "service_read_only",
            "A Zero read handler cannot emit an external effect.",
        ));
    }
    let config = ServiceConfig::from_env();
    match (frame.service.as_str(), frame.operation.as_str()) {
        ("gravatar", "profile") => gravatar_profile(&config, &frame.payload),
        ("spam", "check") => spam_check(&config, &frame.payload),
        ("spam", "report_spam") => spam_report(&config, &frame.payload, "submit-spam"),
        ("spam", "report_ham") => spam_report(&config, &frame.payload, "submit-ham"),
        ("email", "send") => email_send(&config, &frame.payload),
        ("content", "query") => Err(content_query_retired()),
        ("storage", "list") => storage_call(&config, "storage.list", &frame.payload),
        ("storage", "get") => storage_call(&config, "storage.get", &frame.payload),
        ("storage", "delete") => storage_call(&config, "storage.delete", &frame.payload),
        ("storage", "put") => Err(storage_put_unsupported()),
        _ => Err(BrokerRefusal::new(
            "service_operation_unknown",
            format!(
                "{}.{} is not a service operation.",
                frame.service, frame.operation
            ),
        )),
    }
}

/* -------------------------------------------------------------------------- */
/* Space content                                                               */
/* -------------------------------------------------------------------------- */

/// The retired batch-query lane.
///
/// `ctx.content.query` sent `spacefast.content.query` to the Space's content
/// endpoint, which answered it out of a compiled content schema. That schema
/// format and the WordPress projection that executed against it were both
/// retired: the engine now serves content models as Abilities, and a release in
/// the old format is answered with `content_model_republish_required` rather
/// than read. There is nothing left upstream to forward this frame to, so the
/// runner answers it here instead of spending a round trip to collect a generic
/// upstream refusal that names no cause.
///
/// The code is stable and distinct on purpose: a handler that catches it can
/// tell "this Space cannot answer content queries any more" from "the content
/// service is down", and the message names the remedy.
fn content_query_retired() -> BrokerRefusal {
    BrokerRefusal::new(
        "content_query_retired",
        "ctx.content.query is retired. This Space serves its content model as Abilities; call the generated content Ability instead.",
    )
}

/* -------------------------------------------------------------------------- */
/* Space storage                                                               */
/* -------------------------------------------------------------------------- */

/// Why a handler cannot write bytes.
///
/// Not a stub standing in for work that is nearly done: there is no transport
/// for handler-initiated bytes anywhere in this runtime today, and pretending
/// otherwise would mean a `put` that reports success over a write that never
/// happened. The service frame is synchronous JSON capped at
/// `SERVICE_FRAME_MAX_BYTES`, so it is the wrong shape for object bytes even
/// once a destination exists; a streaming path is its own lane. Reads and the
/// trash below go over the Space content endpoint, which already exists.
///
/// Agents are not blocked by this: `zero/storage-upload` uploads for real
/// inside WordPress, where the media library and its capability checks live.
fn storage_put_unsupported() -> BrokerRefusal {
    BrokerRefusal::new(
        "storage_put_unsupported",
        "Zero handlers cannot upload bytes. Use the zero/storage-upload ability.",
    )
}

/// One storage operation, answered by the Space's own content endpoint.
///
/// The same transport `content_query` uses, and the same authority: the runtime
/// forwards this request's access evidence and the endpoint decides. A handler
/// running without it is refused there rather than here — the endpoint owns
/// that verdict, and this must not shadow it with a guess.
fn storage_call(
    config: &ServiceConfig,
    operation: &str,
    payload: &Map<String, Value>,
) -> Result<Value, BrokerRefusal> {
    let Some(url) = config.content_url.as_deref() else {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "This runtime has no Space content endpoint.",
        ));
    };
    if !content_url_valid(url) {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "The Space content endpoint is invalid.",
        ));
    }
    let mut body = payload.clone();
    // Reserved names: the endpoint reads who is asking from the evidence the
    // runtime attached, never from a field tenant code can set.
    for reserved in ["operation", "principal", "managed", "authorization"] {
        body.remove(reserved);
    }
    body.insert(
        "operation".to_string(),
        Value::String(operation.to_string()),
    );
    let encoded = Value::Object(body).to_string();
    if encoded.len() > SERVICE_FRAME_MAX_BYTES {
        return Err(BrokerRefusal::new(
            "service_payload_invalid",
            "The storage request is too large.",
        ));
    }
    let response = content_request(config, url)
        .send(encoded.as_bytes())
        .map_err(|error| match error {
            ureq::Error::StatusCode(status) => BrokerRefusal::new(
                "storage_upstream_refused",
                format!("The Space storage service answered {status}."),
            ),
            _ => BrokerRefusal::new(
                "storage_upstream_unavailable",
                "The Space storage service could not be reached.",
            ),
        })?;
    serde_json::from_str(&read_content_body(response)?).map_err(|_| {
        BrokerRefusal::new(
            "storage_response_invalid",
            "The Space storage service returned an unreadable response.",
        )
    })
}

fn content_request(
    config: &ServiceConfig,
    url: &str,
) -> ureq::RequestBuilder<ureq::typestate::WithBody> {
    let mut request = agent()
        .post(url)
        .header("accept", "application/json")
        .header("content-type", "application/json");
    if let Some(cookie) = config.content_cookie.as_deref() {
        request = request.header("cookie", cookie);
    }
    if let Some(authorization) = config.content_authorization.as_deref() {
        request = request.header("x-sf-authorization", authorization);
    }
    request
}

fn content_url_valid(url: &str) -> bool {
    let Some(authority_and_path) = url.strip_prefix("https://") else {
        return false;
    };
    let Some((authority, path)) = authority_and_path.split_once('/') else {
        return false;
    };
    !authority.is_empty() && !authority.contains(['@', '\\']) && path == "__spacefast/content.php"
}

/* -------------------------------------------------------------------------- */
/* Gravatar                                                                    */
/* -------------------------------------------------------------------------- */

fn gravatar_profile(
    config: &ServiceConfig,
    payload: &Map<String, Value>,
) -> Result<Value, BrokerRefusal> {
    let hash = required_str(payload, "hash")?;
    // The client hashes; this only proves the frame is a hash, so a malformed
    // one cannot be pasted into a URL path.
    if hash.len() != 64 || !hash.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        return Err(BrokerRefusal::new(
            "service_payload_invalid",
            "A Gravatar identifier must be a SHA-256 hex digest.",
        ));
    }

    let mut request = agent()
        .get(format!("{GRAVATAR_PROFILE_BASE}{hash}"))
        .header("accept", "application/json");
    if let Some(key) = &config.gravatar_key {
        request = request.header("authorization", &format!("Bearer {key}"));
    }

    let response = match request.call() {
        Ok(response) => response,
        Err(ureq::Error::StatusCode(404)) => return Ok(Value::Null),
        Err(ureq::Error::StatusCode(429)) => {
            return Err(BrokerRefusal::new(
                "service_rate_limited",
                "Gravatar is rate limiting this space.",
            ))
        }
        Err(ureq::Error::StatusCode(status)) => {
            return Err(BrokerRefusal::new(
                "service_upstream_refused",
                format!("Gravatar answered {status}."),
            ))
        }
        Err(_) => {
            return Err(BrokerRefusal::new(
                "service_upstream_unavailable",
                "Gravatar could not be reached.",
            ))
        }
    };

    let document: Value = serde_json::from_str(&read_body(response)?).map_err(|_| {
        BrokerRefusal::new(
            "service_upstream_unavailable",
            "Gravatar returned an unreadable profile.",
        )
    })?;

    // Projected, never passed through: an upstream field addition must not
    // silently become part of this contract.
    Ok(json!({
        "hash": hash,
        "displayName": optional_field(&document, "display_name"),
        "profileUrl": optional_field(&document, "profile_url"),
        "avatarUrl": optional_field(&document, "avatar_url"),
        "location": optional_field(&document, "location"),
        "description": optional_field(&document, "description"),
        "pronouns": optional_field(&document, "pronouns"),
        "verifiedAccounts": verified_accounts(&document),
    }))
}

fn optional_field(document: &Value, name: &str) -> Value {
    match document.get(name) {
        Some(Value::String(value)) if !value.is_empty() => Value::String(value.clone()),
        _ => Value::Null,
    }
}

fn verified_accounts(document: &Value) -> Value {
    let Some(Value::Array(entries)) = document.get("verified_accounts") else {
        return Value::Array(Vec::new());
    };
    Value::Array(
        entries
            .iter()
            .filter_map(|entry| {
                let service = entry.get("service_type")?.as_str()?;
                let url = entry.get("url")?.as_str()?;
                Some(json!({
                    "service": service,
                    "url": url,
                    "label": optional_field(entry, "service_label"),
                }))
            })
            .collect(),
    )
}

/* -------------------------------------------------------------------------- */
/* Spam                                                                        */
/* -------------------------------------------------------------------------- */

fn spam_check(
    config: &ServiceConfig,
    payload: &Map<String, Value>,
) -> Result<Value, BrokerRefusal> {
    let response = akismet_call(config, payload, "comment-check")?;
    let discard = response
        .headers()
        .get("x-akismet-pro-tip")
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| value.eq_ignore_ascii_case("discard"));
    let body = read_body(response)?;
    let verdict = body.trim();
    // Akismet answers with the literal words. Anything else means the call was
    // malformed or the key was refused, and guessing "not spam" there would
    // silently disable the check.
    match verdict {
        "true" => Ok(json!({ "spam": true, "discard": discard })),
        "false" => Ok(json!({ "spam": false, "discard": false })),
        _ => Err(BrokerRefusal::new(
            "service_upstream_refused",
            "The spam service returned an unexpected verdict.",
        )),
    }
}

fn spam_report(
    config: &ServiceConfig,
    payload: &Map<String, Value>,
    endpoint: &str,
) -> Result<Value, BrokerRefusal> {
    akismet_call(config, payload, endpoint)?;
    Ok(Value::Null)
}

fn akismet_call(
    config: &ServiceConfig,
    payload: &Map<String, Value>,
    endpoint: &str,
) -> Result<ureq::http::Response<ureq::Body>, BrokerRefusal> {
    let Some(key) = &config.akismet_key else {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "Spam checking is not configured for this runtime.",
        ));
    };
    let Some(blog) = &config.blog_url else {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "This space has no canonical origin to check against.",
        ));
    };

    let mut form: Vec<(String, String)> = vec![
        ("api_key".to_string(), key.clone()),
        // Runtime-owned, never from the payload: it is the reputation
        // partition, and a caller that could set it could spend another
        // space's standing.
        ("blog".to_string(), blog.clone()),
        ("blog_charset".to_string(), "UTF-8".to_string()),
        (
            "comment_content".to_string(),
            required_str(payload, "content")?.to_string(),
        ),
        (
            "user_ip".to_string(),
            required_str(payload, "userIp")?.to_string(),
        ),
    ];
    for (field, param) in [
        ("userAgent", "user_agent"),
        ("referrer", "referrer"),
        ("permalink", "permalink"),
        ("type", "comment_type"),
        ("authorName", "comment_author"),
        ("authorEmail", "comment_author_email"),
        ("authorUrl", "comment_author_url"),
        ("authorRole", "user_role"),
        ("createdAt", "comment_date_gmt"),
    ] {
        if let Some(Value::String(value)) = payload.get(field) {
            if !value.is_empty() {
                form.push((param.to_string(), value.clone()));
            }
        }
    }
    if let Some(Value::Array(languages)) = payload.get("languages") {
        let joined = languages
            .iter()
            .filter_map(Value::as_str)
            .collect::<Vec<_>>()
            .join(",");
        if !joined.is_empty() {
            form.push(("blog_lang".to_string(), joined));
        }
    }

    let pairs = form
        .iter()
        .map(|(name, value)| (name.as_str(), value.as_str()));
    agent()
        .post(format!("{AKISMET_BASE}{endpoint}"))
        .header("user-agent", AKISMET_USER_AGENT)
        .send_form(pairs)
        .map_err(|error| match error {
            ureq::Error::StatusCode(status) => BrokerRefusal::new(
                "service_upstream_refused",
                format!("The spam service answered {status}."),
            ),
            _ => BrokerRefusal::new(
                "service_upstream_unavailable",
                "The spam service could not be reached.",
            ),
        })
}

/* -------------------------------------------------------------------------- */
/* Email                                                                       */
/* -------------------------------------------------------------------------- */

/// Accepts one email effect into the space's private outbox.
///
/// On the Zero path this insert joins whatever transaction the handler is
/// already in, which is what makes "the row and the mail commit together" true
/// rather than aspirational. Delivery is somebody else's job and happens later;
/// nothing here contacts a mail transport.
fn email_send(
    config: &ServiceConfig,
    payload: &Map<String, Value>,
) -> Result<Value, BrokerRefusal> {
    if config.space_id.is_empty() || config.invocation_id.is_empty() {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "This runtime has no outbox identity.",
        ));
    }
    // Refused before the row is written, not after.
    //
    // A queued message that no configured sender can carry would sit in the
    // outbox looking accepted and never leave, and "accepted" is the one thing
    // `send()` does promise. Better a refusal the author can see.
    if config.email_senders.is_empty() {
        return Err(BrokerRefusal::new(
            "service_not_configured",
            "This space has no verified sender, so mail cannot be accepted.",
        ));
    }
    let from = payload
        .get("from")
        .and_then(|value| value.get("email"))
        .and_then(Value::as_str)
        .unwrap_or_default()
        .to_ascii_lowercase();
    if !config.email_senders.contains(&from) {
        return Err(BrokerRefusal::new(
            "email_sender_unverified",
            "The from address is not a verified sender for this space.",
        ));
    }
    let normalized = serde_json::to_string(&Value::Object(payload.clone())).map_err(|_| {
        BrokerRefusal::new("email_message_invalid", "The message could not be encoded.")
    })?;

    let effect_index = crate::db::next_email_effect_index();
    // Deterministic rather than random: it is the idempotency identity, so a
    // retried invocation must produce the same id and collide with the row it
    // already wrote instead of sending twice.
    let message_id = message_id(&config.space_id, &config.invocation_id, effect_index);

    crate::db::insert_email_outbox_row(crate::db::EmailOutboxRow {
        message_id: &message_id,
        space_id: &config.space_id,
        version_id: &config.version_id,
        invocation_id: &config.invocation_id,
        effect_index,
        payload_json: &normalized,
    })
    .map_err(|error| BrokerRefusal::new("email_outbox_unavailable", error))?;

    Ok(json!({ "messageId": message_id }))
}

fn message_id(space_id: &str, invocation_id: &str, effect_index: u32) -> String {
    // The NUL separators are what stop (space "a", invocation "bc") and
    // (space "ab", invocation "c") from hashing alike.
    let mut input = Vec::with_capacity(space_id.len() + invocation_id.len() + 6);
    input.extend_from_slice(space_id.as_bytes());
    input.push(0);
    input.extend_from_slice(invocation_id.as_bytes());
    input.push(0);
    input.extend_from_slice(&effect_index.to_be_bytes());
    format!("msg_{}", &crate::artifacts::sha256_hex(&input)[..32])
}

/* -------------------------------------------------------------------------- */
/* Shared plumbing                                                             */
/* -------------------------------------------------------------------------- */

/// One agent for the whole process. `ureq::get`/`ureq::post` build a use-once
/// agent per call, which rebuilds the rustls config — a full copy of the webpki
/// root store — on every connect and throws the connection pool away. An
/// invocation that checks spam and resolves a few profiles would pay a fresh
/// handshake each time, with a visitor waiting.
fn agent() -> &'static ureq::Agent {
    static AGENT: OnceLock<ureq::Agent> = OnceLock::new();
    AGENT.get_or_init(|| {
        ureq::Agent::new_with_config(
            ureq::config::Config::builder()
                .timeout_global(Some(SERVICE_HTTP_TIMEOUT))
                .build(),
        )
    })
}

fn required_str<'a>(
    payload: &'a Map<String, Value>,
    field: &str,
) -> Result<&'a str, BrokerRefusal> {
    payload
        .get(field)
        .and_then(Value::as_str)
        .filter(|value| !value.is_empty())
        .ok_or_else(|| {
            BrokerRefusal::new(
                "service_payload_invalid",
                format!("The {field} field is required."),
            )
        })
}

fn read_body(response: ureq::http::Response<ureq::Body>) -> Result<String, BrokerRefusal> {
    read_body_bounded(
        response.into_body().into_reader(),
        SERVICE_RESPONSE_MAX_BYTES,
        "service_response_too_large",
        "The service response exceeded its size limit.",
    )
}

fn read_content_body(response: ureq::http::Response<ureq::Body>) -> Result<String, BrokerRefusal> {
    read_body_bounded(
        response.into_body().into_reader(),
        CONTENT_RESPONSE_MAX_BYTES,
        "content_response_too_large",
        "The Space content response exceeded the Zero service limit.",
    )
}

fn read_body_bounded(
    reader: impl Read,
    max_bytes: u64,
    too_large_code: &'static str,
    too_large_message: &'static str,
) -> Result<String, BrokerRefusal> {
    let mut body = Vec::new();
    reader
        .take(max_bytes + 1)
        .read_to_end(&mut body)
        .map_err(|_| {
            BrokerRefusal::new(
                "service_upstream_unavailable",
                "The service response could not be read.",
            )
        })?;
    if body.len() as u64 > max_bytes {
        return Err(BrokerRefusal::new(too_large_code, too_large_message));
    }
    String::from_utf8(body).map_err(|_| {
        BrokerRefusal::new(
            "service_upstream_unavailable",
            "The service response could not be read.",
        )
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    fn frame(service: &str, operation: &str, payload: Value) -> String {
        set_zero_execution_mode(None);
        set_grant(ServiceGrant {
            gravatar: true,
            spam: true,
            email: true,
            content: true,
            storage: true,
        });
        handle_service_frame(
            &json!({ "service": service, "operation": operation, "payload": payload }).to_string(),
        )
    }

    fn refusal(raw: &str) -> (String, String) {
        let value: Value = serde_json::from_str(raw).expect("a JSON refusal");
        assert_eq!(value["ok"], Value::Bool(false), "expected a refusal: {raw}");
        (
            value["code"].as_str().unwrap_or_default().to_string(),
            value["message"].as_str().unwrap_or_default().to_string(),
        )
    }

    #[test]
    fn unknown_operations_are_refused_by_name() {
        let (code, message) = refusal(&frame("spam", "delete_everything", json!({})));
        assert_eq!(code, "service_operation_unknown");
        assert!(message.contains("spam.delete_everything"));
        // A service that does not exist cannot be granted either, and the grant
        // is checked first — so it never reaches the operation table at all.
        let (code, _) = refusal(&frame("sms", "send", json!({})));
        assert_eq!(code, "service_capability_denied");
    }

    /// The batch-query lane is gone from the engine, and a frozen capsule still
    /// calls it. It gets a code of its own rather than an upstream refusal that
    /// names no cause, and it never spends a round trip to learn that.
    #[test]
    fn the_retired_content_query_lane_refuses_by_name() {
        let (code, message) = refusal(&frame(
            "content",
            "query",
            json!({ "queries": { "posts": { "collection": "posts" } } }),
        ));
        assert_eq!(code, "content_query_retired");
        assert!(message.contains("Abilities"), "{message}");
    }

    #[test]
    fn malformed_frames_are_refused_before_any_upstream() {
        let (code, _) = refusal(&handle_service_frame("not json"));
        assert_eq!(code, "service_payload_invalid");
        let oversized = "x".repeat(SERVICE_FRAME_MAX_BYTES + 1);
        let (code, _) = refusal(&handle_service_frame(&oversized));
        assert_eq!(code, "service_payload_invalid");
    }

    #[test]
    fn oversized_content_responses_are_refused_without_parsing_truncated_json() {
        let error = read_body_bounded(
            std::io::Cursor::new(vec![b'x'; 5]),
            4,
            "content_response_too_large",
            "too large",
        )
        .expect_err("the fifth byte must cross the limit");
        let (code, message) = refusal(&error.refusal_json());
        assert_eq!(code, "content_response_too_large");
        assert_eq!(message, "too large");
    }

    #[test]
    fn a_gravatar_identifier_must_be_a_digest() {
        // Anything that is not a digest would otherwise be pasted into a URL
        // path, so this is refused before the request is built.
        for identifier in ["../../admin", "", &"z".repeat(64), "abc"] {
            let (code, _) = refusal(&frame("gravatar", "profile", json!({ "hash": identifier })));
            assert_eq!(code, "service_payload_invalid", "for {identifier}");
        }
    }

    #[test]
    fn spam_refuses_before_calling_when_the_runtime_has_no_key() {
        // No AKISMET key is set in the test environment, so this proves the
        // order: configuration is checked before any network work.
        let (code, _) = refusal(&frame(
            "spam",
            "check",
            json!({ "content": "hi", "userIp": "203.0.113.9" }),
        ));
        assert_eq!(code, "service_not_configured");

        set_zero_execution_mode(Some(ExecutionMode::Read));
        let (code, _) = refusal(&handle_service_frame(
            &json!({
                "service": "spam",
                "operation": "report_spam",
                "payload": { "content": "hi", "userIp": "203.0.113.9" }
            })
            .to_string(),
        ));
        assert_eq!(code, "service_read_only");
        set_zero_execution_mode(None);
    }

    #[test]
    fn storage_never_reports_a_write_it_cannot_perform() {
        // `put` is refused by name in every mode, ahead of any transport
        // question: there is no path for handler-initiated bytes, so the one
        // thing this must never do is answer `ok`.
        let (code, message) = refusal(&frame(
            "storage",
            "put",
            json!({ "filename": "a.txt", "contentBase64": "aGk=" }),
        ));
        assert_eq!(code, "storage_put_unsupported");
        // The refusal points at the surface that does upload for real.
        assert!(message.contains("zero/storage-upload"), "{message}");

        // Trashing a file is an effect outside the database, so a replayable
        // read handler is refused it before the endpoint is consulted — while
        // listing stays available and gets as far as the transport check.
        // Dispatched directly rather than through `frame()`, which resets the
        // execution mode as part of its per-call setup.
        set_zero_execution_mode(Some(ExecutionMode::Read));
        let (code, _) = refusal(&handle_service_frame(
            &json!({ "service": "storage", "operation": "delete", "payload": { "id": 7 } })
                .to_string(),
        ));
        assert_eq!(code, "service_read_only");
        let (code, _) = refusal(&handle_service_frame(
            &json!({ "service": "storage", "operation": "list", "payload": {} }).to_string(),
        ));
        assert_eq!(code, "service_not_configured");
        set_zero_execution_mode(None);

        // And an ungranted storage call never reaches any of that.
        set_grant(ServiceGrant::NONE);
        let (code, _) = refusal(&handle_service_frame(
            &json!({ "service": "storage", "operation": "list", "payload": {} }).to_string(),
        ));
        assert_eq!(code, "service_capability_denied");
    }

    #[test]
    fn email_refuses_before_writing_a_row_it_could_not_deliver() {
        // No identity and no verified sender are configured in the test
        // environment, so both gates are reachable here — and both run before
        // anything touches the outbox.
        let (code, _) = refusal(&frame("email", "send", json!({ "subject": "Hi" })));
        assert_eq!(code, "service_not_configured");
    }

    #[test]
    fn message_ids_are_stable_per_effect_and_unique_across_them() {
        let first = message_id("spc_1", "inv_1", 0);
        assert_eq!(first, message_id("spc_1", "inv_1", 0));
        assert_ne!(first, message_id("spc_1", "inv_1", 1));
        assert_ne!(first, message_id("spc_2", "inv_1", 0));
        // The separator is what stops (space "a", invocation "bc") and
        // (space "ab", invocation "c") from hashing alike.
        assert_ne!(message_id("a", "bc", 0), message_id("ab", "c", 0));
        assert!(first.starts_with("msg_"));
    }

    #[test]
    fn an_undeclared_service_is_refused_by_the_broker_itself() {
        set_grant(ServiceGrant {
            gravatar: true,
            spam: false,
            email: false,
            storage: false,
            content: false,
        });
        let (code, message) = refusal(&handle_service_frame(
            &json!({ "service": "spam", "operation": "check", "payload": {} }).to_string(),
        ));
        assert_eq!(code, "service_capability_denied");
        assert!(message.contains("spam"));
        // And the granted one still gets past the gate to its own validation.
        let (code, _) = refusal(&handle_service_frame(
            &json!({ "service": "gravatar", "operation": "profile", "payload": {} }).to_string(),
        ));
        assert_eq!(code, "service_payload_invalid");
    }

    #[test]
    fn an_empty_wire_grant_reaches_nothing() {
        assert_eq!(ServiceGrant::from_wire(""), ServiceGrant::NONE);
        assert_eq!(ServiceGrant::from_wire("db.read,log"), ServiceGrant::NONE);
        assert_eq!(
            ServiceGrant::from_wire("spam.check, email.send"),
            ServiceGrant {
                gravatar: false,
                spam: true,
                email: true,
                content: false,
                storage: false,
            }
        );
    }

    #[test]
    fn content_urls_are_runtime_owned_https_entrypoints() {
        assert!(content_url_valid(
            "https://space.example/__spacefast/content.php"
        ));
        for url in [
            "http://space.example/__spacefast/content.php",
            "https://space.example/__spacefast/content.php?target=other",
            "https://user@space.example/__spacefast/content.php",
            "https://space.example/wp-json/wp/v2/posts",
        ] {
            assert!(!content_url_valid(url), "accepted {url}");
        }
    }

    #[test]
    fn content_requests_forward_runtime_owned_access_evidence() {
        let config = ServiceConfig {
            akismet_key: None,
            gravatar_key: None,
            blog_url: None,
            email_senders: Vec::new(),
            space_id: String::new(),
            version_id: String::new(),
            invocation_id: String::new(),
            content_url: None,
            content_cookie: Some("__Host-spacefast_session=session".to_string()),
            content_authorization: Some("Bearer sfa_access_token".to_string()),
        };
        let request = content_request(&config, "https://space.example/__spacefast/content.php");
        let headers = request
            .headers_ref()
            .expect("valid content request headers");
        assert_eq!(headers["cookie"], "__Host-spacefast_session=session");
        assert_eq!(headers["x-sf-authorization"], "Bearer sfa_access_token");
    }

    #[test]
    fn every_upstream_is_a_declared_host() {
        for base in [GRAVATAR_PROFILE_BASE, AKISMET_BASE] {
            let host = base
                .strip_prefix("https://")
                .and_then(|rest| rest.split('/').next())
                .expect("an https base URL");
            assert!(
                SERVICE_UPSTREAM_HOSTS.contains(&host),
                "{host} is not a declared upstream"
            );
        }
    }
}
