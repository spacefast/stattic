//! URI encoding for Unicode scalar strings, shared by pure configuration evaluation.
pub fn encode(value: &str, component: bool) -> String {
    let mut out = String::new();
    for byte in value.bytes() {
        if byte.is_ascii_alphanumeric()
            || b"-_.!~*'()".contains(&byte)
            || (!component && b";,/?:@&=+$#".contains(&byte))
        {
            out.push(char::from(byte));
        } else {
            use std::fmt::Write;
            write!(out, "%{byte:02X}").expect("writing to String");
        }
    }
    out
}
