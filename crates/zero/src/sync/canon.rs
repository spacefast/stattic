//! Canonical JSON (design §5.3): the bytes a sync record is digested as, identical to
//! `Zero\Sync\Canon` in the runtime. Object keys sorted by byte order at every depth, no whitespace,
//! strings escaped only where JSON requires it, numbers spelled as JavaScript prints them.

use serde_json::Value;
use sha2::{Digest, Sha256};

pub fn json(value: &Value) -> String {
    let mut out = String::new();
    write(value, &mut out);
    out
}

pub fn digest(value: &Value) -> String {
    let hash = Sha256::digest(json(value).as_bytes());
    format!("sha256:{}", hash.iter().map(|b| format!("{b:02x}")).collect::<String>())
}

fn write(value: &Value, out: &mut String) {
    match value {
        Value::Null | Value::Bool(_) | Value::String(_) => out.push_str(&value.to_string()),
        // PHP decodes integers beyond i64 as floats; so does this, to digest the same bytes.
        Value::Number(n) => match n.as_i64() {
            Some(i) => out.push_str(&i.to_string()),
            None => out.push_str(&number(n.as_f64().unwrap_or(f64::NAN))),
        },
        Value::Array(items) => {
            out.push('[');
            for (i, item) in items.iter().enumerate() {
                if i > 0 {
                    out.push(',');
                }
                write(item, out);
            }
            out.push(']');
        }
        Value::Object(members) => {
            let mut keys: Vec<&String> = members.keys().collect();
            keys.sort();
            out.push('{');
            for (i, key) in keys.into_iter().enumerate() {
                if i > 0 {
                    out.push(',');
                }
                out.push_str(&Value::String(key.clone()).to_string());
                out.push(':');
                write(&members[key], out);
            }
            out.push('}');
        }
    }
}

/// ECMAScript Number::toString for a finite double.
pub fn number(f: f64) -> String {
    if f == 0.0 {
        return "0".into();
    }
    let sign = if f < 0.0 { "-" } else { "" };
    // `{:e}` is the shortest round-trip digit string in scientific form: "1.5e-7", "1e21".
    let sci = format!("{:e}", f.abs());
    let (mantissa, exponent) = sci.split_once('e').expect("scientific form");
    let digits: String = mantissa.chars().filter(|c| *c != '.').collect();
    let k = digits.len() as i32;
    let n = exponent.parse::<i32>().expect("exponent") + 1;
    let body = if k <= n && n <= 21 {
        format!("{digits}{}", "0".repeat((n - k) as usize))
    } else if 0 < n && n <= 21 {
        format!("{}.{}", &digits[..n as usize], &digits[n as usize..])
    } else if -6 < n && n <= 0 {
        format!("0.{}{digits}", "0".repeat((-n) as usize))
    } else {
        let e = n - 1;
        let e = if e < 0 { format!("-{}", -e) } else { format!("+{e}") };
        if k == 1 {
            format!("{digits}e{e}")
        } else {
            format!("{}.{}e{e}", &digits[..1], &digits[1..])
        }
    };
    format!("{sign}{body}")
}

#[cfg(test)]
mod tests {
    use std::path::Path;

    /// The golden shared with tests/php/sync_test.php: both runtimes must produce these bytes.
    #[test]
    fn matches_the_shared_golden() {
        let dir = Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/canon");
        let mut seen = 0;
        for entry in std::fs::read_dir(&dir).unwrap() {
            let path = entry.unwrap().path();
            if path.extension().and_then(|e| e.to_str()) != Some("json") {
                continue;
            }
            let value: serde_json::Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
            let canon = std::fs::read_to_string(path.with_extension("canon")).unwrap();
            let digest = std::fs::read_to_string(path.with_extension("sha256")).unwrap();
            assert_eq!(super::json(&value), canon, "{}", path.display());
            assert_eq!(super::digest(&value), digest.trim(), "{}", path.display());
            seen += 1;
        }
        assert!(seen >= 4, "the golden fixtures are missing");
    }
}
