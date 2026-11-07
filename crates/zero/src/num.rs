//! JavaScript number formatting, shared by the evaluator (template literals at
//! build time) and the PHP emitter (literals).

/// `Number.prototype.toString()` for any f64.
pub fn js_number_to_string(x: f64) -> String {
    if x.is_nan() {
        return "NaN".into();
    }
    if x.is_infinite() {
        return if x > 0.0 { "Infinity".into() } else { "-Infinity".into() };
    }
    if x == 0.0 {
        return "0".into();
    }
    // Shortest round-trip digits via Rust's `{:e}`: "d.ddde[-]x".
    let sci = format!("{:e}", x.abs());
    let (mantissa, exp) = sci.split_once('e').expect("exponent form");
    let exp: i32 = exp.parse().expect("exponent");
    let digits: String = mantissa.chars().filter(|c| *c != '.').collect();
    let k = digits.len() as i32;
    let n = exp + 1; // position of the decimal point relative to the digits
    let sign = if x < 0.0 { "-" } else { "" };
    let body = if k <= n && n <= 21 {
        format!("{digits}{}", "0".repeat((n - k) as usize))
    } else if 0 < n && n <= 21 {
        format!("{}.{}", &digits[..n as usize], &digits[n as usize..])
    } else if -6 < n && n <= 0 {
        format!("0.{}{digits}", "0".repeat((-n) as usize))
    } else {
        let e = n - 1;
        let e = if e >= 0 { format!("+{e}") } else { e.to_string() };
        if k == 1 {
            format!("{digits}e{e}")
        } else {
            format!("{}.{}e{e}", &digits[..1], &digits[1..])
        }
    };
    format!("{sign}{body}")
}

/// True when `x` is an integer PHP can hold exactly and JS prints without exponent.
pub fn is_safe_int(x: f64) -> bool {
    x.fract() == 0.0 && x.abs() <= 9_007_199_254_740_991.0 && !(x == 0.0 && x.is_sign_negative())
}

/// A PHP literal for a number.
pub fn php_number(x: f64) -> String {
    if x.is_nan() {
        "NAN".into()
    } else if x.is_infinite() {
        if x > 0.0 { "INF".into() } else { "-INF".into() }
    } else if is_safe_int(x) {
        format!("{}", x as i64)
    } else if x == 0.0 {
        "-0.0".into()
    } else {
        let s = format!("{x:?}");
        if s.contains('.') || s.contains('e') {
            s
        } else {
            format!("{s}.0")
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_javascript() {
        let cases = [
            (0.1 + 0.2, "0.30000000000000004"),
            (1e21, "1e+21"),
            (1e20, "100000000000000000000"),
            (1e-7, "1e-7"),
            (0.000001, "0.000001"),
            (-0.0, "0"),
            (123.456, "123.456"),
            (5e-324, "5e-324"),
            (1.5e300, "1.5e+300"),
            (-42.0, "-42"),
        ];
        for (x, want) in cases {
            assert_eq!(js_number_to_string(x), want, "{x:?}");
        }
    }
}
