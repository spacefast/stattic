use std::collections::BTreeMap;
use std::fmt;
use std::io::Read;
use std::net::{SocketAddr, ToSocketAddrs};
use std::time::Duration;

use base64::Engine;
use serde::Deserialize;
use serde_json::{json, Value};
use stattic_runtime_egress::{
    address_allowed, target_allowed, EgressProfile, InternalHosts, EGRESS_MAX_REDIRECT_HOPS,
    SERVING_INTERNAL_HOSTS,
};
use ureq::config::Config;
use ureq::http::{HeaderName, HeaderValue, Method, Request, Uri};
use ureq::unversioned::resolver::{ResolvedSocketAddrs, Resolver};
use ureq::unversioned::transport::{DefaultConnector, NextTimeout};
use ureq::{Agent, Error};

const FETCH_FRAME_MAX_BYTES: usize = 2 * 1024 * 1024;
const FETCH_BODY_MAX_BYTES: usize = 1024 * 1024;
const FETCH_TIMEOUT: Duration = Duration::from_secs(10);

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

fn internal_hosts() -> InternalHosts {
    let configured = std::env::var("SPACEFAST_ZERO_INTERNAL_HOSTS").unwrap_or_default();
    InternalHosts::from_hosts(
        SERVING_INTERNAL_HOSTS.iter().copied().chain(
            configured
                .split(',')
                .map(str::trim)
                .filter(|host| !host.is_empty()),
        ),
    )
}

fn fetch_agent() -> Agent {
    let config = Agent::config_builder()
        .http_status_as_error(false)
        .https_only(true)
        .max_redirects(EGRESS_MAX_REDIRECT_HOPS)
        .max_redirects_will_error(true)
        .proxy(None)
        .timeout_global(Some(FETCH_TIMEOUT))
        .build();
    Agent::with_parts(
        config,
        DefaultConnector::default(),
        GuardedResolver {
            internal_hosts: internal_hosts(),
        },
    )
}

pub(crate) fn handle_fetch_frame(raw: &str) -> String {
    match execute_fetch_frame(raw) {
        Ok(result) => json!({ "ok": true, "result": result }).to_string(),
        Err((code, message)) => {
            json!({ "ok": false, "code": code, "message": message }).to_string()
        }
    }
}

fn execute_fetch_frame(raw: &str) -> Result<Value, (&'static str, String)> {
    if raw.len() > FETCH_FRAME_MAX_BYTES {
        return Err(fetch_error(
            "zero_fetch_payload_invalid",
            "The fetch request is too large.",
        ));
    }
    let frame: FetchFrame = serde_json::from_str(raw).map_err(|_| {
        fetch_error(
            "zero_fetch_payload_invalid",
            "The fetch request is not valid.",
        )
    })?;
    target_allowed(EgressProfile::TenantFetch, &frame.url, &internal_hosts()).map_err(|_| {
        fetch_error(
            "zero_fetch_target_denied",
            "The fetch target is not permitted.",
        )
    })?;
    let method = Method::from_bytes(frame.method.as_bytes()).map_err(|_| {
        fetch_error(
            "zero_fetch_payload_invalid",
            "The fetch method is not valid.",
        )
    })?;
    if matches!(method, Method::CONNECT | Method::TRACE) {
        return Err(fetch_error(
            "zero_fetch_method_denied",
            "The fetch method is not permitted.",
        ));
    }
    let body = base64::engine::general_purpose::STANDARD
        .decode(&frame.body_base64)
        .map_err(|_| fetch_error("zero_fetch_payload_invalid", "The fetch body is not valid."))?;
    if body.len() > FETCH_BODY_MAX_BYTES {
        return Err(fetch_error(
            "zero_fetch_payload_invalid",
            "The fetch body is too large.",
        ));
    }

    let mut request = Request::builder().method(method).uri(&frame.url);
    for (name, value) in frame.headers {
        let normalized = name.to_ascii_lowercase();
        if is_forbidden_request_header(&normalized) {
            continue;
        }
        let name = HeaderName::from_bytes(normalized.as_bytes()).map_err(|_| {
            fetch_error(
                "zero_fetch_payload_invalid",
                "A fetch header name is not valid.",
            )
        })?;
        let value = HeaderValue::from_str(&value).map_err(|_| {
            fetch_error(
                "zero_fetch_payload_invalid",
                "A fetch header value is not valid.",
            )
        })?;
        request = request.header(name, value);
    }
    let request = request.body(body).map_err(|_| {
        fetch_error(
            "zero_fetch_payload_invalid",
            "The fetch request could not be built.",
        )
    })?;
    let mut response = fetch_agent().run(request).map_err(|_| {
        fetch_error(
            "zero_fetch_upstream_unavailable",
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
            fetch_error(
                "zero_fetch_upstream_unavailable",
                "The fetch response could not be read.",
            )
        })?;
    if response_body.len() > FETCH_BODY_MAX_BYTES {
        return Err(fetch_error(
            "zero_fetch_response_too_large",
            "The fetch response is too large.",
        ));
    }
    Ok(json!({
        "status": status,
        "headers": headers,
        "bodyBase64": base64::engine::general_purpose::STANDARD.encode(response_body),
    }))
}

fn fetch_error(code: &'static str, message: &str) -> (&'static str, String) {
    (code, message.to_string())
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
    fn fetch_frames_reject_tunnel_methods_and_oversized_bodies() {
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
