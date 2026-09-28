use serde::Serialize;
use serde_json::Value;

/// The digest over [`canonical_json`] of a serialized value.
pub fn stable_json_sha256<T: Serialize>(value: &T) -> String {
    let value =
        serde_json::to_value(value).expect("serializing runtime compiler output should not fail");
    format!(
        "sha256:{}",
        crate::finalize::sha256(canonical_json(&value).as_bytes())
    )
}

/// THE canonical JSON spelling, as `packages/common/src/utils/canonical-json.ts`
/// defines it and `canonical-json.fixtures.json` pins it: object keys in Unicode
/// code point order (UTF-8 byte order), no whitespace, only `"`, `\` and C0
/// controls escaped, and numbers as ECMAScript spells them.
///
/// Sorting here rather than relying on `serde_json::Map` keeps the spelling
/// independent of whether some crate in the graph enables `preserve_order`.
pub fn canonical_json(value: &Value) -> String {
    let mut out = String::new();
    write_canonical(value, &mut out);
    out
}

fn write_canonical(value: &Value, out: &mut String) {
    match value {
        Value::Array(entries) => {
            out.push('[');
            for (index, entry) in entries.iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }
                write_canonical(entry, out);
            }
            out.push(']');
        }
        Value::Object(members) => {
            let mut sorted: Vec<_> = members.iter().collect();
            sorted.sort_unstable_by(|(left, _), (right, _)| left.as_bytes().cmp(right.as_bytes()));
            out.push('{');
            for (index, (key, entry)) in sorted.into_iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }
                out.push_str(&Value::String(key.clone()).to_string());
                out.push(':');
                write_canonical(entry, out);
            }
            out.push('}');
        }
        Value::Number(number) => match number.as_f64() {
            Some(float) if number.is_f64() => out.push_str(&ecmascript_number(float)),
            _ => out.push_str(&number.to_string()),
        },
        // serde_json escapes exactly `"`, `\` and C0 controls, like JSON.stringify.
        scalar => out.push_str(&scalar.to_string()),
    }
}

/// ECMAScript `Number::toString` for a finite double (serde_json has no NaN/inf).
fn ecmascript_number(value: f64) -> String {
    if value == 0.0 {
        return "0".into();
    }
    // The fewest digits that read back as the same double, each candidate
    // rounded from the exact value. Plain `{:e}` is also shortest, but when two
    // shortest strings both round-trip it may pick the farther one; ECMAScript
    // (and this) picks the one closest to the exact value.
    let magnitude = value.abs();
    let scientific = (0..17)
        .map(|decimals| format!("{magnitude:.decimals$e}"))
        .find(|candidate| candidate.parse::<f64>() == Ok(magnitude))
        .expect("17 significant digits always round-trip a double");
    let (mantissa, exponent) = scientific
        .split_once('e')
        .expect("scientific formatting always has an exponent");
    let digits: String = mantissa.chars().filter(|c| *c != '.').collect();
    let point = exponent.parse::<i32>().expect("the exponent is an integer") + 1;
    let count = digits.len() as i32;
    let text = if count <= point && point <= 21 {
        format!("{digits}{}", "0".repeat((point - count) as usize))
    } else if 0 < point && point <= 21 {
        format!(
            "{}.{}",
            &digits[..point as usize],
            &digits[point as usize..]
        )
    } else if -6 < point && point <= 0 {
        format!("0.{}{digits}", "0".repeat((-point) as usize))
    } else {
        let shift = point - 1;
        let sign = if shift < 0 { '-' } else { '+' };
        let mantissa = if count == 1 {
            digits
        } else {
            format!("{}.{}", &digits[..1], &digits[1..])
        };
        format!("{mantissa}e{sign}{}", shift.abs())
    };
    if value < 0.0 {
        format!("-{text}")
    } else {
        text
    }
}

// Used only by the native-only zero/compiler modules; compiled out with them.
#[cfg(not(target_family = "wasm"))]
pub(crate) fn sha256_prefixed(bytes: &[u8]) -> String {
    format!("sha256:{}", crate::finalize::sha256(bytes))
}

#[cfg(test)]
mod tests {
    use super::canonical_json;
    use serde_json::Value;

    #[test]
    fn canonical_json_matches_the_shared_corpus() {
        let corpus: Value = serde_json::from_str(include_str!(concat!(
            env!("CARGO_MANIFEST_DIR"),
            "/../../packages/common/src/utils/canonical-json.fixtures.json"
        )))
        .expect("the shared canonical JSON corpus must be valid JSON");
        for case in corpus.as_array().expect("the corpus is an array") {
            assert_eq!(
                canonical_json(&case["value"]),
                case["canonical"].as_str().expect("canonical is a string"),
                "{}",
                case["name"]
            );
        }
    }
}
