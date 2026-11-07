#![expect(
    clippy::result_large_err,
    reason = "RunnerResponse is the serialized process-boundary wire value and stays inline to avoid per-response allocation"
)]

mod broker;
mod execution;
pub mod legacy_zero;
mod services;

use std::io::{self, Read};

pub use broker::{
    DB_OPERATION_MAX_BYTES, DB_PARAM_MAX_COUNT, DB_RESULT_ROWS_MAX, DB_SESSION_PIN,
    DB_TRANSACTION_MAX_STATEMENTS,
};
pub use services::SERVICE_UPSTREAM_HOSTS;

/// The Functions relay's per-request platform-service executor: one frame JSON
/// document on stdin, one result JSON line on stdout, exit 0 either way — so
/// the transport treats protocol refusals and driver failures identically.
/// Which services the frame may name is decided by the relay from the version's
/// grant before this process is ever spawned; by the time a frame arrives the
/// authority question is already answered.
///
/// Reading one byte past the limit is enough — the handler turns the overrun
/// into its own typed refusal without buffering the rest.
pub fn run_service_broker_stdio() {
    services::set_grant(services::ServiceGrant::from_wire(
        &std::env::var(services::SERVICE_BROKER_GRANT_ENV).unwrap_or_default(),
    ));
    let mut input = String::new();
    let outcome = io::stdin()
        .take(services::SERVICE_FRAME_MAX_BYTES as u64 + 1)
        .read_to_string(&mut input);
    let response = match outcome {
        Ok(_) => services::handle_service_frame(&input),
        Err(_) => {
            "{\"ok\":false,\"code\":\"service_payload_invalid\",\"message\":\"The service frame could not be read.\"}"
                .to_string()
        }
    };
    println!("{response}");
}
