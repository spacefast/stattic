use crate::cli::Args;
use crate::sync::client::Credential;
use crate::{platform, wp_plugins::version};
use serde_json::{Value, json};
use std::{process::ExitCode, time::Duration};

fn check(checks: &mut Vec<Value>, name: &str, ok: bool, detail: impl Into<String>) {
    checks.push(json!({"name":name,"ok":ok,"detail":detail.into()}));
}

pub(super) fn run(args: &Args) -> ExitCode {
    let project = args.project.canonicalize().unwrap_or(args.project.clone());
    let out = args.out.clone().unwrap_or_else(|| project.join("sp/dist/wp-content"));
    let mut checks = Vec::new();
    let mut manifest = Value::Null;
    check(
        &mut checks,
        "binary/runtime series",
        env!("CARGO_PKG_VERSION").starts_with(&format!("{}.", platform::SERIES)),
        platform::SERIES,
    );
    {
        match std::fs::read(out.join("spacepress-manifest.json"))
            .and_then(|bytes| serde_json::from_slice(&bytes).map_err(std::io::Error::other))
        {
            Ok(value) => {
                manifest = value;
                let series = manifest["runtime"].as_str().unwrap_or("missing");
                check(&mut checks, "built runtime series", series == platform::SERIES, series);
                for slug in [
                    "spacepress-runtime".to_string(),
                    format!("spacepress-app-{}", manifest["app"].as_str().unwrap_or("")),
                ] {
                    check(
                        &mut checks,
                        &slug,
                        out.join("plugins").join(&slug).is_dir(),
                        "built plugin",
                    );
                }
                check(
                    &mut checks,
                    "identity bundle",
                    out.join("plugins/spacepress-identity/spacepress-identity.php")
                        .is_file(),
                    "optional identity plugin; runtime availability is reported by the site",
                );
            }
            Err(error) => check(
                &mut checks,
                "wp-content artifact",
                false,
                format!("{error}; run spacepress build"),
            ),
        }
    }
    // Signed in as every remote command is (`remote::sign_in`), or signed out without a credential.
    // A refused address or credential is the site check's answer; nothing is sent to it.
    let (site, credential) = match args.site.as_deref().map(|site| (site, super::remote::sign_in(site))) {
        Some((_, Err(error))) => {
            check(&mut checks, "site", false, error);
            (None, None)
        }
        Some((site, Ok(signed))) => (Some(site.to_string()), signed.map(|s| s.credential)),
        None => (None, None),
    };
    if let Some(site) = &site
        && let Err(error) = remote(site, credential.as_ref(), &manifest, &mut checks)
    {
        check(&mut checks, "site", false, error);
    }
    if let Some(site) = &site
        && project.join(crate::wordpress::abilities::LOCK).is_file()
    {
        match super::abilities::list(site, credential.as_ref())
            .and_then(|listed| super::abilities::drift(&project, &listed))
        {
            Ok(drift) => check(
                &mut checks,
                "locked abilities",
                drift.is_empty(),
                if drift.is_empty() {
                    "all present and unchanged".into()
                } else {
                    drift.join("; ")
                },
            ),
            Err(error) => check(&mut checks, "locked abilities", false, error),
        }
    }
    let mut broken = Vec::new();
    if args.links {
        match args.site.as_deref().map(url::Url::parse) {
            Some(Ok(mut site))
                if matches!(site.scheme(), "http" | "https")
                    && site.username().is_empty()
                    && site.password().is_none() =>
            {
                if !site.path().ends_with('/') {
                    site.set_path(&format!("{}/", site.path()));
                }
                let report = super::links::check(&site, &super::links::Http::new());
                let detail = match &report.failed {
                    Some(why) => format!("nothing checked: {why}"),
                    None => format!(
                        "{} pages read, {} broken{}",
                        report.pages,
                        report.broken.len(),
                        if report.truncated {
                            "; stopped at the page limit"
                        } else {
                            ""
                        }
                    ),
                };
                let ok = report.failed.is_none() && report.broken.is_empty() && !report.truncated;
                check(&mut checks, "links", ok, detail);
                broken = report.broken;
            }
            _ => check(
                &mut checks,
                "links",
                false,
                "--links needs --site <http(s) URL> without credentials",
            ),
        }
    }
    let ok = checks.iter().all(|check| check["ok"] == true);
    if args.json {
        println!(
            "{}",
            json!({"data":{"ok":ok,"checks":checks,"requirements":platform::requirements(),"links":broken.iter().map(|b| json!({"on":b.on,"href":b.href,"problem":b.problem})).collect::<Vec<_>>()}})
        );
    } else {
        for item in checks {
            println!(
                "{} {}: {}",
                if item["ok"] == true { "✓" } else { "✗" },
                item["name"].as_str().unwrap(),
                item["detail"].as_str().unwrap()
            );
        }
        for b in &broken {
            println!("  {}: {} {}", b.on, b.href, b.problem);
        }
    }
    if ok { ExitCode::SUCCESS } else { ExitCode::from(1) }
}

fn remote(
    site: &str,
    credential: Option<&Credential>,
    manifest: &Value,
    checks: &mut Vec<Value>,
) -> Result<(), String> {
    let mut url = url::Url::parse(site).map_err(|error| error.to_string())?;
    if !matches!(url.scheme(), "http" | "https") || !url.username().is_empty() || url.password().is_some() {
        return Err("site must be an HTTP(S) URL without credentials".into());
    }
    if url.query().is_some() {
        return Err(
            "site must be the site's URL without a query (https://example.test or https://example.test/blog)".into(),
        );
    }
    // The facade's schema (spec §3a): `GET /wp-json/spacepress/v1`, below the site's path.
    let path = url.path().trim_end_matches('/').to_string();
    let api = crate::sync::client::API;
    url.set_path(&if path.ends_with(api) {
        path
    } else {
        format!("{path}{api}")
    });
    url.set_fragment(None);
    let agent: ureq::Agent = ureq::Agent::config_builder()
        .timeout_global(Some(Duration::from_secs(15)))
        .max_redirects(0)
        .build()
        .into();
    let mut request = agent.get(url.as_str());
    if let Some(credential) = credential {
        request = request.header("Authorization", credential.header());
    }
    let mut response = request
        .call()
        .map_err(|_| "could not read the site's schema; check its URL and credentials".to_string())?;
    let body = response
        .body_mut()
        .read_to_string()
        .map_err(|_| "could not read site diagnostics".to_string())?;
    let description: Value =
        serde_json::from_str(&body).map_err(|_| "site returned an invalid schema document".to_string())?;
    let runtime = &description["data"]["runtime"];
    let series = runtime["series"].as_str().unwrap_or("missing");
    check(checks, "site runtime series", series == platform::SERIES, series);
    {
        let versions = &runtime["wp"];
        let identity = versions["identity"].as_bool();
        let identity_required = !description["data"]["auth"]["collection"].is_null();
        check(
            checks,
            "identity",
            identity.is_some_and(|available| available || !identity_required),
            if identity == Some(true) {
                "compatible identity active"
            } else if identity_required {
                "configured authentication requires a compatible SpacePress Identity"
            } else {
                "optional identity inactive"
            },
        );
        for (name, minimum) in [("wordpress", platform::WORDPRESS), ("php", platform::PHP)] {
            let key = if name == "wordpress" { "version" } else { name };
            let found = versions[key].as_str().unwrap_or("inactive");
            check(
                checks,
                name,
                version::at_least(found, minimum),
                format!("{found}; requires {minimum}+"),
            );
        }
        for plugin in platform::plugins(manifest["requires"].get("polylang").is_some()) {
            let key = match plugin.slug.as_str() {
                "secure-custom-fields" => "scf",
                "action-scheduler" => "actionScheduler",
                "mcp-adapter" => "mcpAdapter",
                "two-factor" => "twoFactor",
                other => other,
            };
            let found = versions[key].as_str().unwrap_or("inactive");
            check(
                checks,
                &plugin.slug,
                version::at_least(found, &plugin.version),
                format!("{found}; requires {}+", plugin.version),
            );
        }
        // Spam checks fail open: this line reports them and never fails doctor.
        let akismet = &versions["akismet"];
        check(
            checks,
            "spam checks",
            true,
            if akismet["spamChecks"] == true {
                format!("on: Akismet {} has a key", akismet["version"].as_str().unwrap_or("?"))
            } else if akismet["version"].is_string() {
                "off: Akismet has no key (connect Jetpack or set WPCOM_API_KEY); comments are not spam-checked"
                    .to_string()
            } else {
                "off: Akismet is not active (set it up through Jetpack); comments are not spam-checked".to_string()
            },
        );
        // Sealed mode (docs/sealed.md). An active plugin the config doesn't list fails doctor. Must-use
        // plugins, drop-ins and theme PHP can't be listed in any config (a host's own are among
        // them), so they are shown and never fail. The site reports; SpacePress stops and removes nothing.
        let sealed = &versions["sealed"];
        let names = |key: &str| -> Option<Vec<String>> {
            sealed[key]
                .as_array()
                .map(|list| list.iter().filter_map(|v| v.as_str().map(str::to_string)).collect())
        };
        match (names("plugins"), names("networkPlugins")) {
            (Some(plugins), Some(network)) => {
                let unlisted: Vec<String> = plugins.into_iter().chain(network).collect();
                let enforced = sealed["enforced"].as_str().unwrap_or("report");
                check(
                    checks,
                    "sealed",
                    unlisted.is_empty(),
                    if unlisted.is_empty() {
                        format!("every active plugin is listed (enforced by {enforced})")
                    } else {
                        format!("active but not in spacepress.config.ts: {}", unlisted.join(", "))
                    },
                );
                let also: Vec<String> = ["muPlugins", "dropins", "theme"]
                    .iter()
                    .flat_map(|key| names(key).unwrap_or_default())
                    .collect();
                check(
                    checks,
                    "sealed: other code WordPress loads",
                    true,
                    if also.is_empty() {
                        "no must-use plugin, drop-in or theme PHP".to_string()
                    } else {
                        also.join(", ")
                    },
                );
            }
            _ if sealed.is_object() => check(
                checks,
                "sealed",
                false,
                "sign in as an administrator (spacepress login, or SP_APP_PASSWORD / SP_TOKEN) to see what the site loads",
            ),
            _ if manifest["sealed"].is_array() => check(
                checks,
                "sealed",
                false,
                "this build is sealed, but the site doesn't report it: deploy this build",
            ),
            _ => {}
        }
        let incompatible = versions["incompatibleTables"].as_array();
        check(
            checks,
            "InnoDB/utf8mb4 tables",
            incompatible.is_some_and(Vec::is_empty),
            incompatible.map_or("missing diagnostics".to_string(), |tables| {
                format!("{} incompatible tables", tables.len())
            }),
        );
        if let Some(expected) = manifest["locales"]["all"].as_array() {
            let expected = language_slugs(expected);
            let actual = language_slugs(runtime["languages"].as_array().map(Vec::as_slice).unwrap_or_default());
            check(
                checks,
                "Polylang languages",
                actual == expected,
                format!("configured {expected:?}; found {actual:?}"),
            );
        }
        if description["data"]["files"]
            .as_object()
            .is_some_and(|files| !files.is_empty())
        {
            check(
                checks,
                "private-file protection",
                runtime["privateFiles"]["protected"] == true,
                "site must report a successful protection probe",
            );
        }
        let database = &versions["database"];
        let found = database["version"].as_str().unwrap_or("0");
        let (name, minimum) = match database["kind"].as_str() {
            Some("mariadb") => ("MariaDB", platform::MARIADB),
            Some("sqlite") => ("SQLite Database Integration", platform::SQLITE),
            _ => ("MySQL", platform::MYSQL),
        };
        check(
            checks,
            "database",
            version::at_least(found, minimum),
            format!("{name} {found}; requires {minimum}+"),
        );
        let pending = runtime["pendingMigrations"].as_array();
        check(
            checks,
            "site pending migrations",
            pending.is_some_and(Vec::is_empty),
            pending.map_or("missing diagnostics".to_string(), |pending| pending.len().to_string()),
        );
    }
    runtime_schema(&description["data"], checks);
    Ok(())
}

/// With `runtimeSchema`: whether the site serves its newest schema version. A version table whose
/// chain doesn't check out, or an option written outside apply, makes it serve less, and says why.
fn runtime_schema(description: &Value, checks: &mut Vec<Value>) {
    let Some(runtime) = description.get("runtimeSchema") else {
        return;
    };
    let version = runtime["version"].as_u64().unwrap_or(0);
    match runtime["problem"].as_str() {
        Some(problem) => check(
            checks,
            "site runtime schema",
            false,
            format!("version {version}: {problem}"),
        ),
        None => check(checks, "site runtime schema", true, format!("version {version}")),
    }
}

/// Locales as Polylang stores them: lowercase slugs (`pt-BR` → `pt-br`), sorted for comparison.
fn language_slugs(list: &[Value]) -> Vec<String> {
    let mut out: Vec<String> = list.iter().map(|l| l.as_str().unwrap_or("").to_lowercase()).collect();
    out.sort();
    out
}

#[cfg(test)]
mod tests {
    use super::runtime_schema;

    #[test]
    fn a_runtime_schema_problem_fails_the_check_and_says_why() {
        let mut checks = Vec::new();
        runtime_schema(&serde_json::json!({ "name": "app" }), &mut checks);
        assert!(checks.is_empty(), "an app without runtimeSchema reports nothing");
        runtime_schema(&serde_json::json!({ "runtimeSchema": { "version": 4 } }), &mut checks);
        runtime_schema(
            &serde_json::json!({ "runtimeSchema": { "version": 3, "problem": "schema version 2 does not match its hash" } }),
            &mut checks,
        );
        assert_eq!(checks[0]["ok"], true, "{checks:?}");
        assert_eq!(checks[1]["ok"], false, "{checks:?}");
        assert_eq!(
            checks[1]["detail"],
            "version 3: schema version 2 does not match its hash"
        );
    }

    #[test]
    fn regional_locales_match_polylang_slugs() {
        let configured = [serde_json::json!("pt-BR"), serde_json::json!("en")];
        let polylang = [serde_json::json!("en"), serde_json::json!("pt-br")];
        assert_eq!(super::language_slugs(&configured), super::language_slugs(&polylang));
    }
}
