pub(crate) const PROTOCOL: &str = "stattic.zero.invoke.v1";
pub const ENDPOINT_FORMAT: &str = "stattic.zero.endpoint.v1";
pub const RUN_FORMAT: &str = "stattic.zero.run.v1";
pub const ENDPOINTS_INDEX_FORMAT: &str = "stattic.zero.endpoints-index.v1";
pub const ENDPOINTS_INDEX_KIND: &str = "zero_endpoints_index";
pub const MIGRATIONS_FORMAT: &str = "stattic.zero.migrations.v1";
pub const RUNNER_ABI: &str = "stattic-zero-runner-abi-v2";
pub const QUICKJS_ABI: &str = "rquickjs-0.12";
/// The image renderer this binary links. It is part of every render-cache key,
/// so an upgrade cannot serve pixels the previous renderer produced. Bump it
/// with the `takumi` dependency — `image::tests` fails if the two disagree.
pub(crate) const RENDERER_ABI: &str = "takumi-2.6.1";

pub(crate) const JS_MEMORY_LIMIT_BYTES: usize = 32 * 1024 * 1024;
pub(crate) const JS_STACK_LIMIT_BYTES: usize = 512 * 1024;
pub(crate) const JS_EXECUTION_TIMEOUT_MS: u64 = 500;

pub(crate) const ZERO_INVOKE_ENVELOPE_MAX_BYTES: usize = 4 * 1024 * 1024;
pub(crate) const ZERO_RESPONSE_BODY_MAX_BYTES: usize = 1024 * 1024;
