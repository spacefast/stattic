//! The `system` config key: a control-plane-only manifest a tenant's
//! designated system space publishes to declare its partner-facing surface
//! (presentation, hostnames, API origins, token issuers, event subscriptions).
//!
//! Unlike `crons` or `runtime`, the config crate is NOT the authority for this
//! section and it produces no runtime artifact. The serving engine never reads
//! it — it rides `fileConfig` and is parsed, validated, and reconciled entirely
//! in the control plane (the `@spacefast/common` `spaceConfigFileSchema` zod
//! contract is the source of truth). The crate's only job is to recognize the
//! key so it is not stripped as unknown, and to pass it through verbatim.
//!
//! This module therefore carries only the JSON-schema fragment both config
//! lanes publish for editor/agent autocomplete. There is deliberately no
//! `validate()`: duplicating the zod shape here would create a second schema
//! that could silently drift from the enforcing one. Exact byte/entry caps and
//! https enforcement live in the control-plane contract (`SPACE_CONFIG_CAPS`).

use serde_json::{json, Value};

/// The JSON-schema fragment for the `system` key. Both config lanes publish it
/// verbatim so the manifest shape is documented next to the key that carries
/// it. Structure and https-URL formats are described here; the control plane
/// enforces the exact string/entry bounds.
#[must_use]
pub fn json_schema() -> Value {
    let https_url = json!({
        "type": "string",
        "format": "uri",
        "pattern": "^https://",
        "description": "An https:// URL."
    });
    let closed = |properties: Value| {
        json!({
            "type": "object",
            "properties": properties,
            "additionalProperties": false
        })
    };
    json!({
        "type": "object",
        "description": "Control-plane manifest for a tenant's designated system space. Parsed and reconciled by the control plane; ignored by other spaces.",
        "properties": {
            "presentation": closed(json!({
                "name": { "type": "string", "description": "Partner display name." },
                "logo": https_url,
                "supportUrl": https_url,
                "termsUrl": {
                    "type": "string",
                    "format": "uri",
                    "pattern": "^https://",
                    "description": "Partner Terms of Service URL shown on white-label preview gates."
                },
                "privacyUrl": {
                    "type": "string",
                    "format": "uri",
                    "pattern": "^https://",
                    "description": "Partner Privacy Policy URL shown on white-label preview gates."
                },
                "claimOrigin": https_url,
                "accessHandoffOrigin": https_url,
                "problemDocsBaseUrl": https_url,
                "contacts": {
                    "type": "object",
                    "description": "Named contact URLs or addresses.",
                    "additionalProperties": { "type": "string" }
                }
            })),
            "spaceBar": {
                "type": "object",
                "description": "Opt-in Space Bar settings for a partner tenant's Spaces.",
                "required": ["enabled"],
                "properties": {
                    "enabled": {
                        "type": "boolean",
                        "description": "Explicit opt-in for the Space Bar on partner Spaces (off by default)."
                    },
                    "panels": {
                        "type": "array",
                        "items": {
                            "type": "string",
                            "enum": ["share", "comments", "menu"]
                        },
                        "uniqueItems": true,
                        "description": "Allowlist of panels shown in the Space Bar; defaults to comments and menu when omitted."
                    },
                    "dashboardUrlTemplate": {
                        "type": "string",
                        "format": "uri",
                        "pattern": "^https://",
                        "description": "Partner dashboard URL template (https://) with optional {spaceId} and {slug} placeholders."
                    },
                    "askYourAgent": {
                        "type": "boolean",
                        "description": "Whether to show Ask Your Agent in the Space Bar menu (default false)."
                    }
                },
                "additionalProperties": false
            },
            "hostnames": closed(json!({
                "apex": { "type": "string", "description": "Apex hostname for the white-label surface." }
            })),
            "api": closed(json!({
                "origin": https_url,
                "allowedOrigins": {
                    "type": "array",
                    "items": https_url,
                    "description": "https origins allowed to call the partner API."
                }
            })),
            "tokenIssuers": {
                "type": "array",
                "description": "One trusted token issuer registered for the tenant. Omit or declare an empty array to revoke it.",
                "items": {
                    "type": "object",
                    "required": ["issuer", "keys"],
                    "properties": {
                        "issuer": { "type": "string", "minLength": 1, "description": "Issuer identifier (iss)." },
                        "keys": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "required": ["kid", "publicKey"],
                                "properties": {
                                    "kid": { "type": "string", "minLength": 1, "description": "Key id." },
                                    "publicKey": { "type": "string", "minLength": 1, "description": "Raw Ed25519 public key (43-character base64url)." }
                                },
                                "additionalProperties": false
                            }
                        },
                        "proofs": {
                            "type": "array",
                            "items": { "type": "string", "minLength": 1 },
                            "description": "Signed challenge JWTs, one per new key, to activate the issuer or rotate keys."
                        }
                    },
                    "additionalProperties": false
                }
            },
            "subscriptions": {
                "type": "array",
                "description": "Event subscriptions (webhooks) the tenant declares.",
                "items": {
                    "type": "object",
                    "required": ["url", "events"],
                    "properties": {
                        "url": https_url,
                        "events": {
                            "type": "array",
                            "items": { "type": "string", "minLength": 1 },
                            "description": "Event names delivered to this subscription."
                        }
                    },
                    "additionalProperties": false
                }
            },
            "plans": {
                "type": "object",
                "description": "Per-tenant plan catalog: plan name -> entitlements. Plan names are arbitrary; feature keys are the fixed Spacefast vocabulary.",
                "additionalProperties": {
                    "type": "object",
                    "required": ["features", "quotas"],
                    "properties": {
                        "features": {
                            "type": "array",
                            "items": { "type": "string", "minLength": 1 },
                            "description": "Feature keys this plan grants. Each must be a known Spacefast feature key."
                        },
                        "quotas": {
                            "type": "object",
                            "properties": {
                                "spaces": {
                                    "type": "integer",
                                    "minimum": 0,
                                    "description": "Max spaces a principal on this plan may own. Omitted = no space-count cap."
                                },
                                "storageBytes": {
                                    "type": "integer",
                                    "minimum": 0,
                                    "description": "Max measured bytes stored by the principal. Omitted = no storage cap."
                                },
                                "buildMinutesPerMonth": {
                                    "type": "integer",
                                    "minimum": 0,
                                    "description": "Max build minutes in one UTC month. Omitted = no monthly build cap."
                                },
                                "customDomains": {
                                    "type": "integer",
                                    "minimum": 0,
                                    "description": "Max active custom domains. Omitted = no custom-domain cap."
                                }
                            },
                            "additionalProperties": false,
                            "description": "Quotas checked at the mutation that can exceed each limit."
                        },
                        "quotaPolicy": {
                            "type": "string",
                            "enum": ["warn", "block"],
                            "description": "How a tripped quota is enforced. Omitted = block."
                        }
                    },
                    "additionalProperties": false
                }
            },
            "defaultPlan": {
                "type": "string",
                "minLength": 1,
                "description": "Plan unassigned principals fall back to. Must be a key in `plans`."
            }
        },
        "additionalProperties": false
    })
}
