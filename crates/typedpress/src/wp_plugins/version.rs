//! WordPress uses PHP version_compare ordering, rather than SemVer.
use std::cmp::Ordering;

fn components(version: &str) -> Vec<String> {
    let mut canonical = String::new();
    let mut previous = None;
    for ch in version.chars() {
        if !ch.is_ascii_alphanumeric() {
            if !canonical.ends_with('.') {
                canonical.push('.');
            }
        } else {
            if previous.is_some_and(|p: char| p.is_ascii_alphanumeric() && p.is_ascii_digit() != ch.is_ascii_digit())
                && !canonical.ends_with('.')
            {
                canonical.push('.');
            }
            canonical.push(ch);
        }
        previous = Some(ch);
    }
    canonical.trim_end_matches('.').split('.').map(str::to_string).collect()
}

fn special(part: &str) -> i8 {
    // PHP special forms use prefix matching and case-sensitive RC/rc aliases.
    [
        ("dev", 0),
        ("alpha", 1),
        ("a", 1),
        ("beta", 2),
        ("b", 2),
        ("RC", 3),
        ("rc", 3),
        ("#", 4),
        ("pl", 5),
        ("p", 5),
    ]
    .into_iter()
    .find_map(|(prefix, rank)| part.starts_with(prefix).then_some(rank))
    .unwrap_or(-1)
}

fn numeric(part: &str) -> bool {
    part.as_bytes().first().is_some_and(u8::is_ascii_digit)
}

fn part_cmp(a: &str, b: &str) -> Ordering {
    match (numeric(a), numeric(b)) {
        (true, true) => {
            let a = a.trim_start_matches('0');
            let b = b.trim_start_matches('0');
            a.len().cmp(&b.len()).then_with(|| a.cmp(b))
        }
        (true, false) => 4.cmp(&special(b)),
        (false, true) => special(a).cmp(&4),
        (false, false) => special(a).cmp(&special(b)),
    }
}

/// Reusable platform floor comparator for resolver, doctor, and harness metadata.
/// Mirrors PHP version_compare for validated WordPress plugin versions (including RC/beta/pl).
pub fn at_least(actual: &str, minimum: &str) -> bool {
    compare(actual, minimum) != Ordering::Less
}

pub fn compare(a: &str, b: &str) -> Ordering {
    let a = components(a);
    let b = components(b);
    for i in 0..a.len().max(b.len()) {
        let order = match (a.get(i), b.get(i)) {
            (Some(a), Some(b)) => part_cmp(a, b),
            (Some(a), None) => {
                if numeric(a) {
                    Ordering::Greater
                } else {
                    part_cmp(a, "#")
                }
            }
            (None, Some(b)) => {
                if numeric(b) {
                    Ordering::Less
                } else {
                    part_cmp("#", b)
                }
            }
            (None, None) => Ordering::Equal,
        };
        if order != Ordering::Equal {
            return order;
        }
    }
    Ordering::Equal
}

pub(super) fn valid(s: &str) -> bool {
    !s.is_empty()
        && s.len() <= 64
        && s.as_bytes()[0].is_ascii_digit()
        && s.bytes().all(|b| b.is_ascii_alphanumeric() || b"._+-".contains(&b))
}

#[cfg(feature = "cli")]
pub(super) fn header_matches(header: &str, declared: &str) -> bool {
    if header == declared {
        return true;
    }
    // WordPress.org sometimes names a 3.9 ZIP whose header is 3.9.0.
    fn numeric_parts(s: &str) -> Option<Vec<u64>> {
        let mut parts: Vec<u64> = s
            .split('.')
            .map(str::parse)
            .collect::<std::result::Result<_, _>>()
            .ok()?;
        while parts.last() == Some(&0) {
            parts.pop();
        }
        Some(parts)
    }
    match (numeric_parts(header), numeric_parts(declared)) {
        (Some(a), Some(b)) => a == b,
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn platform_order_agrees_with_real_php_version_compare() {
        let versions = [
            "3.9",
            "3.9.0",
            "3.9.0.1",
            "3.9-dev",
            "3.9alpha2",
            "3.9-beta1",
            "3.9RC1",
            "3.9rc2",
            "3.9pl1",
            "3.10",
            "4.2.0",
            "4.2.0+build",
            "4.2.0_1",
        ];
        let pairs: Vec<_> = versions
            .iter()
            .flat_map(|a| versions.iter().map(move |b| [*a, *b]))
            .collect();
        let input = serde_json::to_string(&pairs).unwrap();
        let result = std::process::Command::new("php")
            .args([
                "-r",
                "foreach(json_decode($argv[1],true) as [$a,$b]) echo version_compare($a,$b),\"\\n\";",
                &input,
            ])
            .output()
            .unwrap();
        assert!(result.status.success());
        let results = String::from_utf8(result.stdout).unwrap();
        for (pair, expected) in pairs.iter().zip(results.lines()) {
            let expected: i8 = expected.parse().unwrap();
            assert_eq!(
                compare(pair[0], pair[1]),
                expected.cmp(&0),
                "{} vs {}",
                pair[0],
                pair[1]
            );
        }
    }
}
