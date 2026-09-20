//! "Did you mean" for a config key that matched nothing. Shared by every
//! config surface that reports an unknown key, so a typo reads the same
//! wherever it is written.

/// The closest known key within two edits, or `None` when the candidate looks
/// like nothing we accept.
pub fn nearest(candidate: &str, keys: &[&str]) -> Option<String> {
    keys.iter()
        .map(|key| (*key, edit(candidate, key)))
        .filter(|(_, distance)| *distance <= 2)
        .min_by_key(|(_, distance)| *distance)
        .map(|(key, _)| key.into())
}

/// Levenshtein distance, one row at a time.
fn edit(a: &str, b: &str) -> usize {
    let mut prev = (0..=b.len()).collect::<Vec<_>>();
    for (i, ca) in a.chars().enumerate() {
        let mut cur = vec![i + 1];
        for (j, cb) in b.chars().enumerate() {
            cur.push(
                (cur[j] + 1)
                    .min(prev[j + 1] + 1)
                    .min(prev[j] + usize::from(ca != cb)),
            );
        }
        prev = cur;
    }
    *prev.last().unwrap_or(&b.len())
}
