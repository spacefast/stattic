//! Emits the generated finalizer protocol and response-header policy sources.
//!
//! `cargo run -p stattic-runtime-compiler --bin protocol-codegen` prints the
//! TypeScript module (`packages/routing/src/protocol.generated.ts`); pass
//! `-- --php` for the PHP constants
//! (`runtime/engine/shared/finalizer-protocol.generated.php`), or
//! `-- --policy-ts` for the platform-managed response-header lists
//! (`packages/common/src/utils/static-runtime-policy.generated.ts`, whose
//! authority is `crates/stattic-runtime-policy`). Freshness is enforced by
//! `scripts/check-finalizer-protocol.mjs` and the drift tests in
//! `stattic_runtime_core::protocol`.

fn main() {
    let mode = std::env::args().nth(1);
    match mode.as_deref() {
        Some("--php") => print!("{}", stattic_runtime_core::protocol::php_source()),
        Some("--policy-ts") => print!("{}", stattic_runtime_policy::typescript_source()),
        _ => print!("{}", stattic_runtime_core::protocol::typescript_source()),
    }
}
