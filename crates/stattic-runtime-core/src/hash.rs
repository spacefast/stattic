use serde::Serialize;
use sha2::{Digest, Sha256};

pub fn stable_json_sha256<T: Serialize>(value: &T) -> String {
    let bytes =
        serde_json::to_vec(value).expect("serializing runtime compiler output should not fail");
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("sha256:{:x}", hasher.finalize())
}

// Used only by the native-only zero/compiler modules; compiled out with them.
#[cfg(not(target_family = "wasm"))]
pub(crate) fn sha256_hex(bytes: &[u8]) -> String {
    let mut hasher = Sha256::new();
    hasher.update(bytes);
    format!("{:x}", hasher.finalize())
}

#[cfg(not(target_family = "wasm"))]
pub(crate) fn sha256_prefixed(bytes: &[u8]) -> String {
    format!("sha256:{}", sha256_hex(bytes))
}
