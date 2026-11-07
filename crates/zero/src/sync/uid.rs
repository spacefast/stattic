//! Stable identity for files that have no explicit uid.
use sha2::{Digest, Sha256};

pub fn for_file(collection: &str, basename: &str) -> String {
    let hash = Sha256::digest(format!("zero-sync-file\0{collection}\0{basename}"));
    let hex: String = hash.iter().map(|b| format!("{b:02x}")).collect();
    format!("zr_{}", &hex[..20])
}

pub fn valid(u: &str) -> bool {
    u.len() == 23 && u.starts_with("zr_") && u[3..].bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
}

#[cfg(test)]
mod tests {
    #[test]
    fn file_uids_match_php_golden() {
        let cases: serde_json::Value =
            serde_json::from_str(include_str!("../../tests/fixtures/sync-file-uid.json")).unwrap();
        for case in cases.as_array().unwrap() {
            assert_eq!(
                super::for_file(case["collection"].as_str().unwrap(), case["basename"].as_str().unwrap()),
                case["uid"]
            );
        }
    }
}
