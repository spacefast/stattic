//! Emits the generated finalizer protocol sources.
//!
//! `cargo run -p stattic-runtime-compiler --bin protocol-codegen` prints the
//! TypeScript module (`packages/routing/src/protocol.generated.ts`); pass
//! `-- --php` for the PHP constants
//! (`runtime/engine/shared/finalizer-protocol.generated.php`). Freshness is
//! enforced by `scripts/check-finalizer-protocol.mjs` and the drift tests in
//! `stattic_runtime_core::protocol`.

fn main() {
    let php = std::env::args().skip(1).any(|argument| argument == "--php");
    if php {
        print!("{}", stattic_runtime_core::protocol::php_source());
    } else {
        print!("{}", stattic_runtime_core::protocol::typescript_source());
    }
}
