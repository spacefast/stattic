//! Stable identity for files that have no explicit uid.
use sha2::{Digest, Sha256};

pub fn for_file(collection: &str, basename: &str) -> String {
    let hash = Sha256::digest(format!("zero-sync-file\0{collection}\0{basename}"));
    let hex = crate::hex(hash);
    format!("zr_{}", &hex[..20])
}

/// A folder-tree file's identity when it has no uid: its slug, unique in its kind, so moving the
/// file to another folder, or making it its folder's `index` file, keeps the same record.
pub fn for_tree_file(collection: &str, slug: &str) -> String {
    for_file(collection, &format!("{slug}/"))
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
