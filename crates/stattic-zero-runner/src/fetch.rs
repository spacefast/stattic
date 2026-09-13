use std::cell::Cell;
use std::collections::BTreeMap;
use std::fmt;
use std::io::Read;
use std::net::{SocketAddr, ToSocketAddrs};
use std::sync::OnceLock;
use std::time::Duration;

use base64::Engine;
use serde::Deserialize;
use serde_json::{json, Value};
use stattic_runtime_egress::{
    address_allowed, target_allowed, EgressDenial, EgressProfile, EgressScope, InternalHosts,
    EGRESS_MAX_REDIRECT_HOPS, SERVING_INTERNAL_HOSTS,
};
use ureq::config::Config;
use ureq::http::{HeaderName, HeaderValue, Method, Request, Uri};
use ureq::unversioned::resolver::{ResolvedSocketAddrs, Resolver};
use ureq::unversioned::transport::{DefaultConnector, NextTimeout};
use ureq::{Agent, Error};

use crate::artifacts::ExecutionMode;

const FETCH_FRAME_MAX_BYTES: usize = 2 * 1024 * 1024;
const FETCH_BODY_MAX_BYTES: usize = 1024 * 1024;
/// A fetch inside a write invocation holds one of the two pooled MySQL
/// connections open for its whole duration, so it gets the tighter budget.
/// Read and action invocations own no transaction worth starving.
const FETCH_TIMEOUT_WRITE: Duration = Duration::from_millis(5_000);
const FETCH_TIMEOUT: Duration = Duration::from_millis(10_000);

const UNTRUSTED_HOST_MESSAGE: &str =
    "This host isn't reachable from an unclaimed space. Claim the space to reach any public host.";

thread_local! {
    /// Set for the life of one invocation by [`EgressScopeGuard`]. Fails closed
    /// on its own: a code path that forgets the guard reaches the trusted list,
    /// not the internet.
    static EGRESS_SCOPE: Cell<EgressScope> = const { Cell::new(EgressScope::Trusted) };
}

/// Binds the invocation's egress scope for the fetch bridge, which is called
/// from JS and so cannot be handed the envelope.
pub(crate) struct EgressScopeGuard;

impl EgressScopeGuard {
    pub(crate) fn enter(scope: EgressScope) -> Self {
        EGRESS_SCOPE.with(|state| state.set(scope));
        Self
    }
}

impl Drop for EgressScopeGuard {
    fn drop(&mut self) {
        EGRESS_SCOPE.with(|state| state.set(EgressScope::Trusted));
    }
}

fn egress_scope() -> EgressScope {
    EGRESS_SCOPE.with(Cell::get)
}

fn fetch_timeout() -> Duration {
    match crate::services::zero_execution_mode() {
        Some(ExecutionMode::Write) => FETCH_TIMEOUT_WRITE,
        _ => FETCH_TIMEOUT,
    }
}

/// A refused fetch in the shape the JS bridge parses. `status` is what the
/// tenant's `fetch()` reports: a trusted-scope miss is a 403 the author is meant
/// to read as "claim the space", not a transport failure.
struct FetchRefusal {
    code: &'static str,
    status: u16,
    message: String,
}

impl FetchRefusal {
    fn new(code: &'static str, status: u16, message: impl Into<String>) -> Self {
        Self {
            code,
            status,
            message: message.into(),
        }
    }

    fn payload_invalid(message: &str) -> Self {
        Self::new("zero_fetch_payload_invalid", 400, message)
    }

    fn refusal_json(&self) -> String {
        json!({
            "ok": false,
            "code": self.code,
            "status": self.status,
            "message": self.message,
        })
        .to_string()
    }
}

fn target_refusal(denial: EgressDenial) -> FetchRefusal {
    match denial {
        EgressDenial::Untrusted => {
            FetchRefusal::new("zero_fetch_host_untrusted", 403, UNTRUSTED_HOST_MESSAGE)
        }
        _ => FetchRefusal::new(
            "zero_fetch_target_denied",
            403,
            "The fetch target is not permitted.",
        ),
    }
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct FetchFrame {
    url: String,
    #[serde(default = "default_method")]
    method: String,
    #[serde(default)]
    headers: BTreeMap<String, String>,
    #[serde(default)]
    body_base64: String,
}

fn default_method() -> String {
    "GET".to_string()
}

/// Re-applies the whole policy at connect time, including the scope: the
/// pre-flight check runs against the frame's URL, and this runs against every
/// redirect hop the agent follows.
#[derive(Clone)]
struct GuardedResolver {
    internal_hosts: InternalHosts,
}

impl fmt::Debug for GuardedResolver {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.debug_struct("GuardedResolver").finish()
    }
}

impl Resolver for GuardedResolver {
    fn resolve(
        &self,
        uri: &Uri,
        _config: &Config,
        _timeout: NextTimeout,
    ) -> Result<ResolvedSocketAddrs, Error> {
        let target = target_allowed(
            EgressProfile::TenantFetch,
            egress_scope(),
            &uri.to_string(),
            &self.internal_hosts,
        )
        .map_err(|_| Error::HostNotFound)?;
        let resolved: Vec<SocketAddr> = (target.host.as_str(), target.port)
            .to_socket_addrs()
            .map_err(|_| Error::HostNotFound)?
            .collect();
        if resolved.is_empty()
            || resolved
                .iter()
                .any(|address| !address_allowed(address.ip()))
        {
            return Err(Error::HostNotFound);
        }
        let mut addresses = self.empty();
        for address in resolved.into_iter().take(16) {
            addresses.push(address);
        }
        Ok(addresses)
    }
}

fn internal_hosts() -> &'static InternalHosts {
    static INTERNAL_HOSTS: OnceLock<InternalHosts> = OnceLock::new();
    INTERNAL_HOSTS.get_or_init(|| {
        let configured = std::env::var("SPACEFAST_ZERO_INTERNAL_HOSTS").unwrap_or_default();
        InternalHosts::from_hosts(
            SERVING_INTERNAL_HOSTS.iter().copied().chain(
                configured
                    .split(',')
                    .map(str::trim)
                    .filter(|host| !host.is_empty()),
            ),
        )
    })
}

/// One agent for the whole process, for the same reason the service broker
/// keeps one: a fresh agent per call rebuilds the rustls root store and throws
/// the connection pool away, so a handler that fetches twice pays two cold
/// handshakes with a visitor waiting.
fn fetch_agent() -> &'static Agent {
    static AGENT: OnceLock<Agent> = OnceLock::new();
    AGENT.get_or_init(|| {
        let config = Agent::config_builder()
            .http_status_as_error(false)
            .https_only(true)
            .max_redirects(EGRESS_MAX_REDIRECT_HOPS)
            .max_redirects_will_error(true)
            .proxy(None)
            // Per-request, because the budget is the invocation's mode, not the
            // agent's. This is the ceiling the request-level override lowers.
            .timeout_global(Some(FETCH_TIMEOUT))
            .build();
        Agent::with_parts(
            config,
            DefaultConnector::default(),
            GuardedResolver {
                internal_hosts: internal_hosts().clone(),
            },
        )
    })
}

pub(crate) fn handle_fetch_frame(raw: &str) -> String {
    match execute_fetch_frame(raw) {
        Ok(result) => json!({ "ok": true, "result": result }).to_string(),
        Err(error) => error.refusal_json(),
    }
}

fn execute_fetch_frame(raw: &str) -> Result<Value, FetchRefusal> {
    if raw.len() > FETCH_FRAME_MAX_BYTES {
        return Err(FetchRefusal::payload_invalid(
            "The fetch request is too large.",
        ));
    }
    let frame: FetchFrame = serde_json::from_str(raw)
        .map_err(|_| FetchRefusal::payload_invalid("The fetch request is not valid."))?;
    target_allowed(
        EgressProfile::TenantFetch,
        egress_scope(),
        &frame.url,
        internal_hosts(),
    )
    .map_err(target_refusal)?;
    let method = Method::from_bytes(frame.method.as_bytes())
        .map_err(|_| FetchRefusal::payload_invalid("The fetch method is not valid."))?;
    if matches!(method, Method::CONNECT | Method::TRACE) {
        return Err(FetchRefusal::new(
            "zero_fetch_method_denied",
            403,
            "The fetch method is not permitted.",
        ));
    }
    let body = base64::engine::general_purpose::STANDARD
        .decode(&frame.body_base64)
        .map_err(|_| FetchRefusal::payload_invalid("The fetch body is not valid."))?;
    if body.len() > FETCH_BODY_MAX_BYTES {
        return Err(FetchRefusal::payload_invalid(
            "The fetch body is too large.",
        ));
    }

    let mut request = Request::builder().method(method).uri(&frame.url);
    for (name, value) in frame.headers {
        let normalized = name.to_ascii_lowercase();
        if is_forbidden_request_header(&normalized) {
            continue;
        }
        let name = HeaderName::from_bytes(normalized.as_bytes())
            .map_err(|_| FetchRefusal::payload_invalid("A fetch header name is not valid."))?;
        let value = HeaderValue::from_str(&value)
            .map_err(|_| FetchRefusal::payload_invalid("A fetch header value is not valid."))?;
        request = request.header(name, value);
    }
    let request = request
        .body(body)
        .map_err(|_| FetchRefusal::payload_invalid("The fetch request could not be built."))?;
    let agent = fetch_agent();
    let request = agent
        .configure_request(request)
        .timeout_global(Some(fetch_timeout()))
        .build();
    let mut response = agent.run(request).map_err(|_| {
        FetchRefusal::new(
            "zero_fetch_upstream_unavailable",
            502,
            "The fetch target could not be reached.",
        )
    })?;
    let status = response.status().as_u16();
    let headers = response
        .headers()
        .iter()
        .filter_map(|(name, value)| {
            let name = name.as_str().to_ascii_lowercase();
            (!is_forbidden_response_header(&name))
                .then(|| value.to_str().ok().map(|value| (name, value.to_string())))
                .flatten()
        })
        .collect::<BTreeMap<_, _>>();
    let mut response_body = Vec::new();
    response
        .body_mut()
        .as_reader()
        .take(FETCH_BODY_MAX_BYTES as u64 + 1)
        .read_to_end(&mut response_body)
        .map_err(|_| {
            FetchRefusal::new(
                "zero_fetch_upstream_unavailable",
                502,
                "The fetch response could not be read.",
            )
        })?;
    if response_body.len() > FETCH_BODY_MAX_BYTES {
        return Err(FetchRefusal::new(
            "zero_fetch_response_too_large",
            502,
            "The fetch response is too large.",
        ));
    }
    Ok(json!({
        "status": status,
        "headers": headers,
        "bodyBase64": base64::engine::general_purpose::STANDARD.encode(response_body),
    }))
}

fn is_forbidden_request_header(name: &str) -> bool {
    matches!(
        name,
        "connection"
            | "content-length"
            | "host"
            | "keep-alive"
            | "proxy-authorization"
            | "proxy-connection"
            | "te"
            | "trailer"
            | "transfer-encoding"
            | "upgrade"
    )
}

fn is_forbidden_response_header(name: &str) -> bool {
    matches!(
        name,
        "connection"
            | "keep-alive"
            | "proxy-authenticate"
            | "set-cookie"
            | "set-cookie2"
            | "te"
            | "trailer"
            | "transfer-encoding"
            | "upgrade"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn fetch_frames_reject_non_https_and_private_targets_before_transport() {
        let _scope = EgressScopeGuard::enter(EgressScope::Open);
        for url in [
            "http://example.com/",
            "https://127.0.0.1/",
            "https://[::1]/",
            "https://site.view.fast/",
        ] {
            let response: Value = serde_json::from_str(&handle_fetch_frame(
                &json!({ "url": url, "method": "GET" }).to_string(),
            ))
            .expect("response");
            assert_eq!(response["ok"], false, "{url}");
            assert_eq!(response["code"], "zero_fetch_target_denied", "{url}");
        }
    }

    #[test]
    fn an_anonymous_space_reaches_the_trusted_list_and_is_told_how_to_widen_it() {
        let refused: Value = serde_json::from_str(&handle_fetch_frame(
            &json!({ "url": "https://example.com/", "method": "GET" }).to_string(),
        ))
        .expect("response");
        assert_eq!(refused["ok"], false);
        assert_eq!(refused["code"], "zero_fetch_host_untrusted");
        assert_eq!(refused["status"], 403);
        assert_eq!(refused["message"], UNTRUSTED_HOST_MESSAGE);

        // Same scope, a host the platform owns: the refusal is about the list,
        // not about fetch being off.
        let allowed: Value = serde_json::from_str(&handle_fetch_frame(
            // A denied method short-circuits after the target check, so this
            // proves the target passed without leaving the process.
            &json!({ "url": "https://api.github.com/zen", "method": "CONNECT" }).to_string(),
        ))
        .expect("response");
        assert_eq!(allowed["code"], "zero_fetch_method_denied");

        // The denylist is untouched by scope: the reason stays the address, so
        // nobody is told to claim a space to reach cloud metadata.
        let denied: Value = serde_json::from_str(&handle_fetch_frame(
            &json!({ "url": "https://169.254.169.254/", "method": "GET" }).to_string(),
        ))
        .expect("response");
        assert_eq!(denied["code"], "zero_fetch_target_denied");
    }

    #[test]
    fn the_fetch_budget_is_the_invocations_mode_and_the_guard_fails_closed() {
        assert_eq!(fetch_timeout(), FETCH_TIMEOUT);
        crate::services::set_zero_execution_mode(Some(ExecutionMode::Write));
        assert_eq!(fetch_timeout(), FETCH_TIMEOUT_WRITE);
        crate::services::set_zero_execution_mode(Some(ExecutionMode::Read));
        assert_eq!(fetch_timeout(), FETCH_TIMEOUT);
        crate::services::set_zero_execution_mode(None);

        assert_eq!(egress_scope(), EgressScope::Trusted);
        {
            let _scope = EgressScopeGuard::enter(EgressScope::Open);
            assert_eq!(egress_scope(), EgressScope::Open);
        }
        assert_eq!(egress_scope(), EgressScope::Trusted);
    }

    #[test]
    fn fetch_frames_reject_tunnel_methods_and_oversized_bodies() {
        let _scope = EgressScopeGuard::enter(EgressScope::Open);
        let tunnel: Value = serde_json::from_str(&handle_fetch_frame(
            &json!({ "url": "https://example.com/", "method": "CONNECT" }).to_string(),
        ))
        .expect("response");
        assert_eq!(tunnel["code"], "zero_fetch_method_denied");

        let oversized: Value = serde_json::from_str(&handle_fetch_frame(
            &json!({
                "url": "https://example.com/",
                "method": "POST",
                "bodyBase64": base64::engine::general_purpose::STANDARD.encode(vec![0; FETCH_BODY_MAX_BYTES + 1]),
            })
            .to_string(),
        ))
        .expect("response");
        assert_eq!(oversized["code"], "zero_fetch_payload_invalid");
    }
}
