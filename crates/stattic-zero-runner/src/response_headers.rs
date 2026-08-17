use std::collections::BTreeMap;

const RESPONSE_HEADER_MAX_COUNT: usize = 64;
const RESPONSE_HEADER_NAME_MAX_BYTES: usize = 128;
const RESPONSE_HEADER_VALUE_MAX_BYTES: usize = 8 * 1024;
const RESPONSE_HEADERS_MAX_BYTES: usize = 64 * 1024;

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub struct ResponseHeaderPolicyError {
    pub code: &'static str,
    pub message: &'static str,
}

/// The Rust authority for response headers a Zero endpoint may set.
///
/// The runner applies this policy to every endpoint response before the
/// response crosses the process boundary; the PHP request adapter keeps only a
/// fail-closed boundary filter in case an invalid or compromised runner tries
/// to bypass this native policy.
///
/// The platform-managed list below intentionally duplicates
/// `stattic-runtime-core/src/policy.rs::platform_managed_response_header` (the
/// `_headers` compile/apply authority): runtime-core depends on this crate, so
/// the runner cannot import it without inverting the dependency. Both are ports
/// of one TypeScript definition
/// (`packages/common/src/utils/static-runtime-policy.ts`);
/// `apps/control-plane/src/runtime/php-policy-parity.test.ts` fails if any of
/// them drift apart.
#[must_use]
pub fn platform_managed_response_header(name: &str) -> bool {
    let name = name.trim().to_ascii_lowercase();
    name.starts_with("x-spacefast-")
        || name.starts_with("x-stattic-")
        || matches!(
            name.as_str(),
            "accept-ranges"
                | "age"
                | "allow"
                | "alt-svc"
                | "cdn-cache-control"
                | "cloudflare-cdn-cache-control"
                | "connection"
                | "content-encoding"
                | "content-length"
                | "content-range"
                | "cookie"
                | "date"
                | "host"
                | "keep-alive"
                | "location"
                | "netlify-cdn-cache-control"
                | "proxy-authenticate"
                | "proxy-authorization"
                | "server"
                | "set-cookie"
                | "strict-transport-security"
                | "surrogate-control"
                | "te"
                | "trailer"
                | "transfer-encoding"
                | "upgrade"
                | "vary"
                // The internal-redirect/sendfile family, every vendor spelling: these
                // instruct the web server to serve a different file instead of
                // describing this response.
                | "x-accel-buffering"
                | "x-accel-charset"
                | "x-accel-expires"
                | "x-accel-limit-rate"
                | "x-accel-redirect"
                | "x-lighttpd-send-file"
                | "x-lighttpd-sendfile"
                | "x-lighttpd-sendfile2"
                | "x-reproxy-url"
                | "x-sendfile"
        )
}

pub fn validate_response_headers(
    headers: BTreeMap<String, String>,
) -> Result<BTreeMap<String, String>, ResponseHeaderPolicyError> {
    if headers.len() > RESPONSE_HEADER_MAX_COUNT {
        return Err(invalid("Zero response contains too many headers."));
    }

    let mut total_bytes = 0_usize;
    let mut normalized = BTreeMap::new();
    for (name, value) in headers {
        let name = name.trim().to_ascii_lowercase();
        total_bytes = total_bytes
            .saturating_add(name.len())
            .saturating_add(value.len());
        if name.is_empty()
            || name.len() > RESPONSE_HEADER_NAME_MAX_BYTES
            || !name
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
            || value.len() > RESPONSE_HEADER_VALUE_MAX_BYTES
            || value.contains(['\r', '\n', '\0'])
            || total_bytes > RESPONSE_HEADERS_MAX_BYTES
        {
            return Err(invalid("Zero response contains an invalid header."));
        }
        if platform_managed_response_header(&name) {
            return Err(ResponseHeaderPolicyError {
                code: "zero_response_header_forbidden",
                message: "Zero response attempted to set a platform-managed header.",
            });
        }
        if normalized.insert(name, value).is_some() {
            return Err(invalid(
                "Zero response contains duplicate case-insensitive headers.",
            ));
        }
    }
    Ok(normalized)
}

const fn invalid(message: &'static str) -> ResponseHeaderPolicyError {
    ResponseHeaderPolicyError {
        code: "zero_response_header_invalid",
        message,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_platform_and_transport_headers_case_insensitively() {
        for name in [
            "X-Accel-Redirect",
            "x-sendfile",
            "Transfer-Encoding",
            "Connection",
            "Upgrade",
            "Trailer",
            "Set-Cookie",
            "Location",
            "X-Spacefast-Zero-Runner-Metrics",
            "x-stattic-anything",
        ] {
            let error = validate_response_headers(BTreeMap::from([(
                name.to_string(),
                "attempted".to_string(),
            )]))
            .unwrap_err();
            assert_eq!(error.code, "zero_response_header_forbidden", "{name}");
        }
    }

    #[test]
    fn normalizes_safe_headers_and_rejects_ambiguous_duplicates() {
        let headers = validate_response_headers(BTreeMap::from([
            ("Content-Type".to_string(), "application/json".to_string()),
            ("X-App-Result".to_string(), "ready".to_string()),
        ]))
        .unwrap();
        assert_eq!(
            headers.get("content-type").map(String::as_str),
            Some("application/json")
        );
        assert_eq!(
            headers.get("x-app-result").map(String::as_str),
            Some("ready")
        );

        let error = validate_response_headers(BTreeMap::from([
            ("X-App".to_string(), "one".to_string()),
            ("x-app".to_string(), "two".to_string()),
        ]))
        .unwrap_err();
        assert_eq!(error.code, "zero_response_header_invalid");
    }

    #[test]
    fn rejects_invalid_bytes_and_oversized_headers() {
        for (name, value) in [
            ("x-newline", "evil\r\ninjected: 1".to_string()),
            ("x-nul", "evil\0".to_string()),
            ("x big", "space in name".to_string()),
            ("", "empty name".to_string()),
            (
                "x-oversized",
                "a".repeat(RESPONSE_HEADER_VALUE_MAX_BYTES + 1),
            ),
            (
                // Name longer than the per-name cap.
                &"n".repeat(RESPONSE_HEADER_NAME_MAX_BYTES + 1),
                "value".to_string(),
            ),
        ] {
            let error =
                validate_response_headers(BTreeMap::from([(name.to_string(), value)])).unwrap_err();
            assert_eq!(error.code, "zero_response_header_invalid", "{name}");
        }
    }

    #[test]
    fn rejects_header_sets_over_the_count_and_total_size_budgets() {
        let too_many = (0..=RESPONSE_HEADER_MAX_COUNT)
            .map(|index| (format!("x-h-{index}"), "v".to_string()))
            .collect::<BTreeMap<_, _>>();
        assert_eq!(
            validate_response_headers(too_many).unwrap_err().code,
            "zero_response_header_invalid"
        );

        // Each value stays under the per-value cap, but together they exceed
        // the total-bytes budget.
        let oversized_total = (0..16)
            .map(|index| {
                (
                    format!("x-total-{index}"),
                    "t".repeat(RESPONSE_HEADER_VALUE_MAX_BYTES),
                )
            })
            .collect::<BTreeMap<_, _>>();
        assert_eq!(
            validate_response_headers(oversized_total).unwrap_err().code,
            "zero_response_header_invalid"
        );
    }
}
