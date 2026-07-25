pub(crate) const PROTOCOL: &str = "stattic.zero.invoke.v1";
pub(crate) const ENDPOINT_FORMAT: &str = "stattic.zero.endpoint.v1";
pub(crate) const RUNNER_ABI: &str = "stattic-zero-runner-abi-v2";
pub(crate) const QUICKJS_ABI: &str = "rquickjs-0.12";

pub(crate) const JS_MEMORY_LIMIT_BYTES: usize = 32 * 1024 * 1024;
pub(crate) const JS_STACK_LIMIT_BYTES: usize = 512 * 1024;
pub(crate) const JS_EXECUTION_TIMEOUT_MS: u64 = 500;

pub(crate) const ZERO_INVOKE_ENVELOPE_MAX_BYTES: usize = 4 * 1024 * 1024;
pub(crate) const ZERO_RESPONSE_BODY_MAX_BYTES: usize = 1024 * 1024;
