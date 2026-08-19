use serde::Serialize;

pub fn stable_json_sha256<T: Serialize>(value: &T) -> String {
    let bytes =
        serde_json::to_vec(value).expect("serializing runtime compiler output should not fail");
    format!("sha256:{}", crate::finalize::sha256(&bytes))
}

// Used only by the native-only zero/compiler modules; compiled out with them.
#[cfg(not(target_family = "wasm"))]
pub(crate) fn sha256_prefixed(bytes: &[u8]) -> String {
    format!("sha256:{}", crate::finalize::sha256(bytes))
}
