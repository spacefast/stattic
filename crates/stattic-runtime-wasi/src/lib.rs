//! Thin WASI (wasm32-wasip1) FFI surface over `stattic-runtime-core`.
//!
//! This crate exists only to give the JSON dispatch in
//! `stattic_runtime_core::prepare_abi` a `cdylib` boundary; no product logic
//! belongs here. Build with
//! `cargo build --release --locked --target wasm32-wasip1 -p stattic-runtime-wasi`
//! (wrapped by `scripts/build-runtime-wasi.mjs`); the artifact ships to
//! JavaScript consumers as `stattic-runtime-core.wasm`.
//!
//! Memory ceiling: the native finalizer binary applies a 384 MiB additional
//! address-space rlimit via `stattic_zero_runner::apply_process_address_space_limit`.
//! That ceiling is native-only — a wasm32 module is capped at 4 GiB by the
//! architecture and, in practice, by whatever limit the embedding host places
//! on the instance's linear memory — so no in-module ceiling is enforced here.

use serde_json::json;
use stattic_runtime_core::prepare_abi::dispatch_json;
use stattic_runtime_core::routing::{compile_routing_files, RoutingInput};

/// Allocates a zeroed buffer the host can write request bytes into.
#[no_mangle]
pub extern "C" fn sf_alloc(length: usize) -> *mut u8 {
    Box::into_raw(vec![0; length].into_boxed_slice()).cast::<u8>()
}

/// Releases a buffer previously returned by [`sf_alloc`] or an exported ABI
/// function that transfers ownership to its caller.
///
/// # Safety
///
/// `pointer` must be either null or a live allocation returned by this crate,
/// and `capacity` must exactly match that allocation's reported byte length.
/// The allocation must not be accessed or released again after this call.
#[no_mangle]
pub unsafe extern "C" fn sf_dealloc(pointer: *mut u8, capacity: usize) {
    if !pointer.is_null() {
        drop(unsafe { Box::from_raw(std::ptr::slice_from_raw_parts_mut(pointer, capacity)) });
    }
}

/// Runs a versioned prepare operation supplied as JSON request bytes.
///
/// # Safety
///
/// `pointer` must reference `length` readable bytes for the duration of this
/// call. The returned packed pointer/length owns its allocation; callers
/// release it exactly once with [`sf_dealloc`].
#[no_mangle]
pub unsafe extern "C" fn sf_prepare_finalize(pointer: *const u8, length: usize) -> u64 {
    let input = unsafe { std::slice::from_raw_parts(pointer, length) };
    pack_output(dispatch_json(input))
}

/// Compiles routing JSON supplied through the shared WASM ABI.
///
/// # Safety
///
/// `pointer` must reference at least `length` readable bytes for the duration
/// of this call. The returned packed pointer and length transfer ownership of
/// the output buffer to the caller, which must release it with [`sf_dealloc`].
#[no_mangle]
pub unsafe extern "C" fn sf_compile_routing(pointer: *const u8, length: usize) -> u64 {
    let input = unsafe { std::slice::from_raw_parts(pointer, length) };
    let output = serde_json::from_slice::<RoutingInput>(input)
        .map(|input| compile_routing_files(&input))
        .and_then(|result| serde_json::to_vec(&result));
    let bytes = match output {
        Ok(bytes) => bytes,
        Err(error) => serde_json::to_vec(&json!({ "error": error.to_string() }))
            .expect("error envelope serializes"),
    };
    pack_output(bytes)
}

#[cfg(target_pointer_width = "32")]
fn pack_output(bytes: Vec<u8>) -> u64 {
    let bytes = bytes.into_boxed_slice();
    let length = bytes.len();
    let pointer = Box::into_raw(bytes).cast::<u8>();
    ((pointer as u64) << 32) | length as u64
}

#[cfg(not(target_pointer_width = "32"))]
fn pack_output(_bytes: Vec<u8>) -> u64 {
    // Keep host compilation available for workspace clippy/tests, but make an
    // accidental host invocation fail explicitly instead of truncating a
    // 64-bit pointer into the wasm32 wire format.
    panic!("stattic-runtime-wasi requires a 32-bit wasm32-wasip1 target")
}
