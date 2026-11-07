// The removable capsule API; shared services never import this module.
#[path = "legacy/zero-0.8/mod.rs"]
mod implementation;

pub use implementation::{
    compile_endpoint_program, handle_invoke, run_stdio, self_test, CompiledEndpointProgram,
    ZeroEndpointCapabilities, DB_CAPABILITY_ABI, QUICKJS_ABI, RUNNER_ABI,
    ZERO_ENDPOINTS_INDEX_FORMAT, ZERO_ENDPOINTS_INDEX_KIND, ZERO_ENDPOINT_FORMAT,
    ZERO_MIGRATIONS_FORMAT, ZERO_RUN_FORMAT,
};
