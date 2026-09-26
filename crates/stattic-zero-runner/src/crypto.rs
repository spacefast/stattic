//! `ctx.jwt` and `ctx.crypto`: token signing, hashing and MAC checks for
//! handler code, computed here rather than in QuickJS, which has no WebCrypto.
//!
//! Keys are named, never passed. A handler writes `key: "PARTNER_SIGNING_KEY"`,
//! the compiler records every such name on the artifact, and the invocation
//! moves those variables out of the handler's `ctx.env` and into this keyring
//! before any tenant code runs. A key value therefore never becomes a
//! JavaScript string. A name the artifact did not record is refused: the
//! keyring holds only what the compiler saw, so a computed name reaches nothing.

use std::cell::RefCell;
use std::collections::BTreeMap;
use std::time::{SystemTime, UNIX_EPOCH};

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use ring::rand::{SecureRandom, SystemRandom};
use ring::{hmac, signature::Ed25519KeyPair};
use serde::Deserialize;
use serde_json::{json, Map, Value};
use subtle::ConstantTimeEq;

use crate::artifacts::sha256_hex;
use crate::db::BrokerRefusal;

/// Token lifetime when the handler names none.
pub(crate) const JWT_DEFAULT_TTL_SECONDS: u64 = 300;
/// The longest lifetime a handler may ask for: the Partner API's own ceiling
/// (`EXTERNAL_IDENTITY_MAX_TTL_SECONDS`), so a token minted here is never one
/// the verifier refuses for living too long.
pub(crate) const JWT_MAX_TTL_SECONDS: u64 = 30 * 60;
/// Claims this runtime owns. A handler that sets one gets a refusal, not a
/// silent overwrite it would later debug.
const RUNTIME_CLAIMS: [&str; 3] = ["iat", "exp", "jti"];
const CLAIMS_MAX_BYTES: usize = 4096;
const KID_MAX_CHARS: usize = 64;
/// Inputs are request-sized: a webhook body, an API key, a subject.
const DATA_MAX_BYTES: usize = 1024 * 1024;

thread_local! {
    static KEYRING: RefCell<BTreeMap<String, String>> = const { RefCell::new(BTreeMap::new()) };
}

/// Replaces the keyring for the invocation on this thread. Always called, even
/// with nothing, so a key from an earlier invocation cannot survive into this one.
pub(crate) fn set_keyring(keys: BTreeMap<String, String>) {
    KEYRING.with(|state| *state.borrow_mut() = keys);
}

/// Splits the invocation's variables into what `ctx.env` shows and what only
/// this module may read. Every name the artifact records as a key leaves
/// `ctx.env` and enters the keyring, empty when no value is set, so a declared
/// key without a value is `crypto_key_unavailable`, never `crypto_key_unknown`.
pub(crate) fn partition_variables(
    variables: &BTreeMap<String, String>,
    key_names: &[String],
) -> (BTreeMap<String, String>, BTreeMap<String, String>) {
    let keys = key_names
        .iter()
        .map(|name| {
            (
                name.clone(),
                variables.get(name).cloned().unwrap_or_default(),
            )
        })
        .collect();
    let visible = variables
        .iter()
        .filter(|(name, _)| !key_names.contains(name))
        .map(|(name, value)| (name.clone(), value.clone()))
        .collect();
    (visible, keys)
}

#[derive(Deserialize)]
struct CryptoFrame {
    operation: String,
    #[serde(default)]
    payload: Value,
}

/// One frame in, one JSON answer out, in the service broker's envelope, so the
/// prelude surfaces a refusal the same way it surfaces a service refusal.
pub(crate) fn handle_crypto_frame(raw: &str) -> String {
    match execute(raw) {
        Ok(result) => json!({ "ok": true, "result": result }).to_string(),
        Err(error) => error.refusal_json(),
    }
}

fn execute(raw: &str) -> Result<Value, BrokerRefusal> {
    if raw.len() > DATA_MAX_BYTES + 4096 {
        return Err(invalid("The crypto input is too large."));
    }
    let frame: CryptoFrame =
        serde_json::from_str(raw).map_err(|_| invalid("The crypto frame is not valid."))?;
    match frame.operation.as_str() {
        "jwt_sign" => {
            let options: JwtSignOptions = parse(frame.payload, "ctx.jwt.sign")?;
            let seed = key_value(&options.key)?;
            Ok(Value::String(sign_jwt(
                &seed,
                &options,
                unix_now(),
                &random_jti()?,
            )?))
        }
        "sha256" => {
            let input: DataInput = parse(frame.payload, "ctx.crypto.sha256")?;
            Ok(Value::String(sha256_hex(bounded(&input.data)?)))
        }
        "hmac_sha256" => {
            let input: HmacInput = parse(frame.payload, "ctx.crypto.hmacSha256")?;
            let key = match input.key {
                HmacKey::Named(name) => key_value(&name)?,
                HmacKey::Inline { value } => value,
            };
            Ok(Value::String(hmac_sha256_hex(
                key.as_bytes(),
                bounded(&input.data)?,
            )))
        }
        "timing_safe_equal" => {
            let input: CompareInput = parse(frame.payload, "ctx.crypto.timingSafeEqual")?;
            Ok(Value::Bool(timing_safe_equal(
                bounded(&input.a)?,
                bounded(&input.b)?,
            )))
        }
        other => Err(BrokerRefusal::new(
            "crypto_operation_unknown",
            format!("{other} is not a crypto operation."),
        )),
    }
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields, rename_all = "camelCase")]
pub(crate) struct JwtSignOptions {
    key: String,
    kid: String,
    claims: Map<String, Value>,
    #[serde(default)]
    ttl_seconds: Option<u64>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct DataInput {
    data: String,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct HmacInput {
    key: HmacKey,
    data: String,
}

#[derive(Deserialize)]
#[serde(untagged)]
enum HmacKey {
    Named(String),
    Inline { value: String },
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct CompareInput {
    a: String,
    b: String,
}

fn parse<T: for<'de> Deserialize<'de>>(payload: Value, call: &str) -> Result<T, BrokerRefusal> {
    serde_json::from_value(payload)
        .map_err(|error| invalid(format!("{call} received invalid options: {error}.")))
}

fn invalid(message: impl Into<String>) -> BrokerRefusal {
    BrokerRefusal::new("crypto_payload_invalid", message)
}

fn bounded(value: &str) -> Result<&[u8], BrokerRefusal> {
    if value.len() > DATA_MAX_BYTES {
        return Err(invalid("The crypto input exceeds 1 MiB."));
    }
    Ok(value.as_bytes())
}

/// The value of a key the artifact recorded. Two refusals, because the author
/// fixes them in different places: an unrecorded name in their code, a missing
/// value in their Space's variables.
fn key_value(name: &str) -> Result<String, BrokerRefusal> {
    KEYRING.with(|state| match state.borrow().get(name) {
        Some(value) if !value.trim().is_empty() => Ok(value.trim().to_string()),
        Some(_) => Err(BrokerRefusal::new(
            "crypto_key_unavailable",
            format!("Set the secret variable {name} for this Space, then publish again."),
        )),
        None => Err(BrokerRefusal::new(
            "crypto_key_unknown",
            format!(
                "{name} is not a key this handler declared. Write the key name as a string literal in the call so the publish can find it."
            ),
        )),
    })
}

/// Signs an `at+jwt` with the Ed25519 seed in `seed_base64url`. `now` and
/// `jti` are parameters so a test can pin them; the frame handler passes the
/// clock and a fresh random value.
pub(crate) fn sign_jwt(
    seed_base64url: &str,
    options: &JwtSignOptions,
    now: u64,
    jti: &str,
) -> Result<String, BrokerRefusal> {
    let key_pair = ed25519_key_pair(&options.key, seed_base64url)?;
    if options.kid.is_empty() || options.kid.chars().count() > KID_MAX_CHARS {
        return Err(invalid("ctx.jwt.sign needs a kid of 1 to 64 characters."));
    }
    let ttl = options.ttl_seconds.unwrap_or(JWT_DEFAULT_TTL_SECONDS);
    if ttl == 0 || ttl > JWT_MAX_TTL_SECONDS {
        return Err(invalid(format!(
            "ctx.jwt.sign ttlSeconds must be between 1 and {JWT_MAX_TTL_SECONDS}."
        )));
    }
    if let Some(claim) = RUNTIME_CLAIMS
        .iter()
        .find(|claim| options.claims.contains_key(**claim))
    {
        return Err(invalid(format!(
            "ctx.jwt.sign sets {claim} itself. Remove it from claims."
        )));
    }
    let mut claims = options.claims.clone();
    claims.insert("iat".into(), json!(now));
    claims.insert("exp".into(), json!(now + ttl));
    claims.insert("jti".into(), json!(jti));
    let claims = serde_json::to_vec(&claims).map_err(|_| invalid("The claims are not JSON."))?;
    if claims.len() > CLAIMS_MAX_BYTES {
        return Err(invalid("ctx.jwt.sign claims exceed 4 KiB."));
    }
    // The header is fixed: a handler chooses its key id, never its algorithm,
    // type, or a key-location header a verifier might follow.
    let header =
        serde_json::to_vec(&json!({ "alg": "EdDSA", "typ": "at+jwt", "kid": options.kid }))
            .map_err(|_| invalid("The header is not JSON."))?;
    let signing_input = format!(
        "{}.{}",
        URL_SAFE_NO_PAD.encode(header),
        URL_SAFE_NO_PAD.encode(claims)
    );
    let signature = key_pair.sign(signing_input.as_bytes());
    Ok(format!(
        "{signing_input}.{}",
        URL_SAFE_NO_PAD.encode(signature.as_ref())
    ))
}

fn ed25519_key_pair(name: &str, seed_base64url: &str) -> Result<Ed25519KeyPair, BrokerRefusal> {
    let refused = || {
        BrokerRefusal::new(
            "crypto_key_invalid",
            format!("{name} must hold a base64url Ed25519 seed of 32 bytes."),
        )
    };
    let seed = URL_SAFE_NO_PAD
        .decode(seed_base64url.trim_end_matches('='))
        .map_err(|_| refused())?;
    if seed.len() != 32 {
        return Err(refused());
    }
    Ed25519KeyPair::from_seed_unchecked(&seed).map_err(|_| refused())
}

pub(crate) fn hmac_sha256_hex(key: &[u8], data: &[u8]) -> String {
    let tag = hmac::sign(&hmac::Key::new(hmac::HMAC_SHA256, key), data);
    tag.as_ref()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

/// Constant time over equal lengths. Lengths are not secret for the values
/// this compares — hex digests and signatures of a fixed size.
pub(crate) fn timing_safe_equal(a: &[u8], b: &[u8]) -> bool {
    a.len() == b.len() && bool::from(a.ct_eq(b))
}

fn unix_now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|elapsed| elapsed.as_secs())
        .unwrap_or_default()
}

fn random_jti() -> Result<String, BrokerRefusal> {
    let mut bytes = [0u8; 16];
    SystemRandom::new().fill(&mut bytes).map_err(|_| {
        BrokerRefusal::new(
            "crypto_unavailable",
            "The runtime could not read randomness.",
        )
    })?;
    Ok(URL_SAFE_NO_PAD.encode(bytes))
}

#[cfg(test)]
mod tests {
    use super::*;
    use ring::signature::{KeyPair, UnparsedPublicKey, ED25519};

    // RFC 8032 section 7.1, test 1.
    const RFC8032_SEED: &str = "9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60";
    const RFC8032_PUBLIC: &str = "d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a";

    fn hex(value: &str) -> Vec<u8> {
        (0..value.len())
            .step_by(2)
            .map(|index| u8::from_str_radix(&value[index..index + 2], 16).expect("hex"))
            .collect()
    }

    fn seed() -> String {
        URL_SAFE_NO_PAD.encode(hex(RFC8032_SEED))
    }

    fn options(claims: Value, ttl_seconds: Option<u64>) -> JwtSignOptions {
        JwtSignOptions {
            key: "PARTNER_SIGNING_KEY".into(),
            kid: "webhosting-1".into(),
            claims: claims.as_object().expect("claims object").clone(),
            ttl_seconds,
        }
    }

    fn decode(segment: &str) -> Value {
        serde_json::from_slice(&URL_SAFE_NO_PAD.decode(segment).expect("base64url")).expect("json")
    }

    fn refused(error: BrokerRefusal) -> Value {
        serde_json::from_str(&error.refusal_json()).expect("refusal json")
    }

    fn frame(operation: &str, payload: Value) -> Value {
        serde_json::from_str(&handle_crypto_frame(
            &json!({ "operation": operation, "payload": payload }).to_string(),
        ))
        .expect("answer json")
    }

    #[test]
    fn a_signed_token_verifies_under_the_seed_public_key_with_the_pinned_header() {
        // The seed format is the RFC 8032 secret key, the same 32 bytes a JWK
        // `d` carries, so its public key is the RFC's and the token verifies
        // under exactly the value a partner registers as `publicKey`.
        let key_pair = Ed25519KeyPair::from_seed_unchecked(&hex(RFC8032_SEED)).expect("rfc seed");
        assert_eq!(
            key_pair.public_key().as_ref(),
            hex(RFC8032_PUBLIC).as_slice()
        );

        let claims = json!({
            "iss": "https://webhosting.example",
            "aud": "partner_audience",
            "sub": "customer-42",
            "client_id": "webhosting",
            "scope": ["spaces:write"],
        });
        let token = sign_jwt(
            &seed(),
            &options(claims.clone(), Some(600)),
            1_700_000_000,
            "jti-1",
        )
        .expect("token");
        let parts: Vec<&str> = token.split('.').collect();
        assert_eq!(parts.len(), 3);

        UnparsedPublicKey::new(&ED25519, hex(RFC8032_PUBLIC))
            .verify(
                format!("{}.{}", parts[0], parts[1]).as_bytes(),
                &URL_SAFE_NO_PAD.decode(parts[2]).expect("signature"),
            )
            .expect("signature verifies");
        assert_eq!(
            decode(parts[0]),
            json!({ "alg": "EdDSA", "typ": "at+jwt", "kid": "webhosting-1" })
        );
        let mut expected = claims;
        expected["iat"] = json!(1_700_000_000);
        expected["exp"] = json!(1_700_000_600);
        expected["jti"] = json!("jti-1");
        assert_eq!(decode(parts[1]), expected);
    }

    #[test]
    fn ttl_is_bounded_and_runtime_claims_are_the_runtime_s() {
        let claims = json!({ "sub": "customer-42" });
        let default_ttl =
            sign_jwt(&seed(), &options(claims.clone(), None), 100, "j").expect("token");
        assert_eq!(
            decode(default_ttl.split('.').nth(1).expect("claims"))["exp"],
            json!(100 + JWT_DEFAULT_TTL_SECONDS)
        );
        assert!(sign_jwt(
            &seed(),
            &options(claims.clone(), Some(JWT_MAX_TTL_SECONDS)),
            0,
            "j"
        )
        .is_ok());
        for ttl in [0, JWT_MAX_TTL_SECONDS + 1] {
            let refusal = refused(
                sign_jwt(&seed(), &options(claims.clone(), Some(ttl)), 0, "j")
                    .expect_err("out of bounds"),
            );
            assert_eq!(refusal["code"], "crypto_payload_invalid", "{ttl}");
        }
        let refusal = refused(
            sign_jwt(&seed(), &options(json!({ "exp": 1 }), None), 0, "j")
                .expect_err("runtime claim"),
        );
        assert!(
            refusal["message"]
                .as_str()
                .unwrap_or_default()
                .contains("exp"),
            "{refusal}"
        );
    }

    #[test]
    fn signing_needs_a_recorded_key_with_a_seed_and_no_header_overrides() {
        set_keyring(BTreeMap::from([
            ("PARTNER_SIGNING_KEY".to_string(), seed()),
            ("EMPTY_KEY".to_string(), String::new()),
            ("SHORT_KEY".to_string(), URL_SAFE_NO_PAD.encode([7u8; 16])),
        ]));
        let sign = |key: &str, extra: Value| {
            let mut payload = json!({ "key": key, "kid": "k1", "claims": { "sub": "s" } });
            for (name, value) in extra.as_object().expect("extra") {
                payload[name] = value.clone();
            }
            frame("jwt_sign", payload)
        };

        let signed = sign("PARTNER_SIGNING_KEY", json!({}));
        assert_eq!(signed["ok"], true, "{signed}");
        let jti = decode(
            signed["result"]
                .as_str()
                .expect("token")
                .split('.')
                .nth(1)
                .expect("claims"),
        )["jti"]
            .clone();
        assert_eq!(jti.as_str().map(str::len), Some(22), "16 random bytes");

        for (key, extra, code) in [
            ("NOT_DECLARED", json!({}), "crypto_key_unknown"),
            ("EMPTY_KEY", json!({}), "crypto_key_unavailable"),
            ("SHORT_KEY", json!({}), "crypto_key_invalid"),
            (
                "PARTNER_SIGNING_KEY",
                json!({ "header": { "alg": "none" } }),
                "crypto_payload_invalid",
            ),
        ] {
            assert_eq!(sign(key, extra)["code"], code, "{key}");
        }
        set_keyring(BTreeMap::new());
        assert_eq!(
            sign("PARTNER_SIGNING_KEY", json!({}))["code"],
            "crypto_key_unknown"
        );
    }

    #[test]
    fn sha256_and_hmac_match_the_published_vectors() {
        // FIPS 180-2 appendix B.1.
        assert_eq!(
            frame("sha256", json!({ "data": "abc" }))["result"],
            "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad"
        );
        // RFC 4231 test cases 1 and 2: an inline key, then the same key by name.
        assert_eq!(
            frame(
                "hmac_sha256",
                json!({ "key": { "value": "\u{0b}".repeat(20) }, "data": "Hi There" })
            )["result"],
            "b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7"
        );
        set_keyring(BTreeMap::from([(
            "WEBHOOK_SECRET".to_string(),
            "Jefe".to_string(),
        )]));
        assert_eq!(
            frame(
                "hmac_sha256",
                json!({ "key": "WEBHOOK_SECRET", "data": "what do ya want for nothing?" })
            )["result"],
            "5bdcc146bf60754e6a042426089575c75a003f089d2739839dec58b964ec3843"
        );
        assert_eq!(
            frame("hmac_sha256", json!({ "key": "OTHER", "data": "x" }))["code"],
            "crypto_key_unknown"
        );
        set_keyring(BTreeMap::new());
    }

    #[test]
    fn timing_safe_equal_compares_whole_values() {
        assert_eq!(
            frame("timing_safe_equal", json!({ "a": "abc", "b": "abc" }))["result"],
            true
        );
        assert_eq!(
            frame("timing_safe_equal", json!({ "a": "abc", "b": "abd" }))["result"],
            false
        );
        assert_eq!(
            frame("timing_safe_equal", json!({ "a": "abc", "b": "abcd" }))["result"],
            false
        );
    }
}
