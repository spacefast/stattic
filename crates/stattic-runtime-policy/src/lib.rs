//! The single authority for platform-managed response headers.
//!
//! Three enforcement points ask this question and must give one answer: the
//! `_headers` compiler in `stattic-runtime-core` (which also builds for
//! `wasm32-wasip1`), the Zero runner's response validator in
//! `stattic-zero-runner`, and the PHP serving engine. The first two sit on
//! opposite sides of a dependency diamond — `stattic-runtime-core` depends on
//! `stattic-zero-runner`, but only on native targets, because the runner
//! embeds QuickJS — so neither can host the list for the other. This leaf
//! crate is the shared floor both reach unconditionally, the same role
//! `stattic-runtime-egress` plays for outbound policy.
//!
//! TypeScript and PHP are generated, never transcribed:
//! [`typescript_source`] emits
//! `packages/common/src/utils/static-runtime-policy.generated.ts`, which
//! `packages/common/src/utils/static-runtime-policy.ts` re-exports and
//! `apps/control-plane/src/scripts/codegen-php-policy.ts` compiles into
//! `runtime/engine/shared/safety.php`. `scripts/check-finalizer-protocol.mjs`
//! fails when the checked-in TypeScript is stale.
//!
//! Adding a header here means: `bun scripts/check-finalizer-protocol.mjs
//! --write`, then `bun --filter @spacefast/control-plane
//! runtime:codegen-policy` — in that order, because PHP is generated from the
//! generated TypeScript.

/// Reserved response-header namespaces owned by the platform's own serving
/// signature (`X-Spacefast-Runtime`, `X-Spacefast-Version`,
/// `X-Spacefast-Reason`, …). User rules can never forge or clobber them.
pub const PLATFORM_MANAGED_RESPONSE_HEADER_PREFIXES: &[&str] = &["x-spacefast-", "x-stattic-"];

/// The connection and transport surface: hop-by-hop names (Connection,
/// Keep-Alive, TE, Trailer, Transfer-Encoding, Upgrade, Proxy-\*), framing, and
/// the edge-cache trio. A publisher describes content, not the connection that
/// carries it.
pub const TRANSPORT_MANAGED_RESPONSE_HEADERS: &[&str] = &[
    "accept-ranges",
    "age",
    "allow",
    "alt-svc",
    "cdn-cache-control",
    "cloudflare-cdn-cache-control",
    "connection",
    "content-encoding",
    "content-length",
    "content-range",
    "cookie",
    "date",
    "host",
    "keep-alive",
    "location",
    "netlify-cdn-cache-control",
    "proxy-authenticate",
    "proxy-authorization",
    "server",
    "set-cookie",
    "strict-transport-security",
    "surrogate-control",
    "te",
    "trailer",
    "transfer-encoding",
    "upgrade",
    "vary",
];

/// The internal-redirect/sendfile family, every vendor spelling: headers that
/// do not describe a response, they instruct the web server to produce a
/// different one. Under nginx+php-fpm `X-Accel-Redirect` turns a response into
/// a server-side read of an arbitrary internal path — a private-path
/// disclosure primitive — while `X-Accel-Limit-Rate` pins the serving worker
/// for the length of the transfer.
///
/// These are dangerous from ANY origin we do not control, so they are named
/// apart from the transport set above: the relay lane
/// (`runtime/engine/runtime/proxy.php`) must drop exactly this family from an
/// untrusted upstream while still relaying the ordinary response headers
/// (location, content-encoding, content-range, allow) the managed set also
/// contains.
pub const INTERNAL_REDIRECT_RESPONSE_HEADERS: &[&str] = &[
    "x-accel-buffering",
    "x-accel-charset",
    "x-accel-expires",
    "x-accel-limit-rate",
    "x-accel-redirect",
    "x-lighttpd-send-file",
    "x-lighttpd-sendfile",
    "x-lighttpd-sendfile2",
    "x-reproxy-url",
    "x-sendfile",
];

/// True when user rules may neither set nor remove this response header.
///
/// The prefixes are half the policy — never ask the literal sets alone.
#[must_use]
pub fn platform_managed_response_header(name: &str) -> bool {
    let name = name.trim().to_ascii_lowercase();
    PLATFORM_MANAGED_RESPONSE_HEADER_PREFIXES
        .iter()
        .any(|prefix| name.starts_with(prefix))
        || TRANSPORT_MANAGED_RESPONSE_HEADERS.contains(&name.as_str())
        || INTERNAL_REDIRECT_RESPONSE_HEADERS.contains(&name.as_str())
}

fn typescript_string_array(values: &[&str]) -> String {
    let entries = values
        .iter()
        .map(|value| format!("\"{value}\""))
        .collect::<Vec<_>>()
        .join(", ");
    format!("[{entries}]")
}

/// The generated TypeScript module the control plane, the `_headers` compiler
/// and the PHP policy codegen all read.
///
/// `PLATFORM_MANAGED_RESPONSE_HEADERS` is emitted as the concatenation of the
/// two disjoint Rust sets, transport first, so every header name is written
/// down exactly once in this crate and nowhere else in the repository.
#[must_use]
pub fn typescript_source() -> String {
    let managed = TRANSPORT_MANAGED_RESPONSE_HEADERS
        .iter()
        .chain(INTERNAL_REDIRECT_RESPONSE_HEADERS.iter())
        .copied()
        .collect::<Vec<_>>();
    format!(
        "// @generated by `cargo run -p stattic-runtime-compiler --bin protocol-codegen -- --policy-ts`.\n\
         // `crates/stattic-runtime-policy/src/lib.rs` is the only editable authority.\n\
         \n\
         /** Reserved response-header namespaces carrying the platform's own serving signature. */\n\
         export const PLATFORM_MANAGED_RESPONSE_HEADER_PREFIXES: readonly string[] = {prefixes};\n\
         \n\
         /**\n\
         \x20* The internal-redirect/sendfile family, every vendor spelling: headers that make the web\n\
         \x20* server produce a different response instead of describing this one. Factored out of the\n\
         \x20* managed set below so the proxy relay can drop exactly these from an untrusted upstream\n\
         \x20* while still relaying ordinary response headers the managed set also lists.\n\
         \x20*/\n\
         export const INTERNAL_REDIRECT_RESPONSE_HEADERS: readonly string[] = {internal_redirect};\n\
         \n\
         /**\n\
         \x20* Rejected/platform-managed response headers: user `_headers` rules can never set or remove\n\
         \x20* these. The internal-redirect family above is a strict subset — anything the proxy lane\n\
         \x20* refuses to relay is also refused from `_headers`. Ask isPlatformManagedResponseHeader(),\n\
         \x20* not this list alone: the reserved prefixes are the other half of the policy.\n\
         \x20*/\n\
         export const PLATFORM_MANAGED_RESPONSE_HEADERS: readonly string[] = {managed};\n",
        prefixes = typescript_string_array(PLATFORM_MANAGED_RESPONSE_HEADER_PREFIXES),
        internal_redirect = typescript_string_array(INTERNAL_REDIRECT_RESPONSE_HEADERS),
        managed = typescript_string_array(&managed),
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn managed_headers_are_matched_case_and_whitespace_insensitively() {
        for name in [
            "  Transfer-Encoding ",
            "X-Accel-Redirect",
            "SET-COOKIE",
            "x-spacefast-runtime",
            "X-Stattic-Anything",
        ] {
            assert!(platform_managed_response_header(name), "{name}");
        }
        for name in [
            "content-type",
            "x-app-result",
            "cache-control",
            "x-spacefast",
        ] {
            assert!(!platform_managed_response_header(name), "{name}");
        }
    }

    #[test]
    fn the_two_literal_sets_are_disjoint_so_generated_lists_carry_no_duplicates() {
        for name in INTERNAL_REDIRECT_RESPONSE_HEADERS {
            assert!(
                !TRANSPORT_MANAGED_RESPONSE_HEADERS.contains(name),
                "{name} is defined twice"
            );
        }
    }
}
