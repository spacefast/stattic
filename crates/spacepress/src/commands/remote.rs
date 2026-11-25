//! A WordPress site running the SpacePress plugin, over HTTP: finding its REST API, the credential
//! every remote command signs in with ([`sign_in`]), and its deploy receiver
//! (`/wp-json/spacepress-deploy/v1`, served by runtime/spacepress-plugin/src/Receiver.php).
use crate::sync::client::Credential;
use serde_json::{Value, json};
use std::path::{Path, PathBuf};
use std::time::Duration;

/// The deploy protocol this command speaks (`Site::PROTOCOL` in the plugin).
pub(crate) const PROTOCOL: u64 = 1;
const NAMESPACE: &str = "spacepress-deploy/v1";

/// A site's URL as it is stored and compared: scheme, host, port and path, no trailing slash. An
/// address that carries a user or password is refused without being repeated: it would land in the
/// terminal's scrollback and in logs.
pub(crate) fn normalize(site: &str) -> Result<String, String> {
    let url = url::Url::parse(site).map_err(|e| match site.contains('@') {
        true => format!("a site is an http(s) URL without a user or password, like https://example.com ({e})"),
        false => format!("{site}: {e}. A site is an http(s) URL, like https://example.com"),
    })?;
    if !url.username().is_empty() || url.password().is_some() {
        return Err(format!(
            "{}://{}: give the site's address without a user or password; spacepress login asks the site for a credential",
            url.scheme(),
            url.host_str().unwrap_or("the site")
        ));
    }
    if !matches!(url.scheme(), "http" | "https") || url.host_str().is_none() {
        return Err(format!("{site}: a site is an http(s) URL, like https://example.com"));
    }
    if url.query().is_some() || url.fragment().is_some() {
        return Err(format!(
            "{site}: give the site's address only, without a query or fragment"
        ));
    }
    Ok(url.as_str().trim_end_matches('/').to_string())
}

fn agent(seconds: u64) -> ureq::Agent {
    ureq::Agent::config_builder()
        .timeout_global(Some(Duration::from_secs(seconds)))
        .http_status_as_error(false)
        .max_redirects(0)
        .build()
        .into()
}

/// What WordPress's REST index says about a site, signed out.
pub(crate) struct Discovered {
    /// The REST root that answered: `<site>/wp-json`, or `<site>/?rest_route=` without pretty permalinks.
    pub(crate) rest: String,
    pub(crate) name: String,
    /// Core's Authorize Application screen, when the site accepts application passwords.
    pub(crate) authorize: Option<String>,
    /// The SpacePress plugin is active.
    pub(crate) plugin: bool,
}

/// Read the site's REST index. Redirects are reported, never followed: a credential is only ever
/// sent to the address the user typed.
pub(crate) fn discover(site: &str) -> Result<Discovered, String> {
    let agent = agent(30);
    let mut last = String::new();
    for rest in [format!("{site}/wp-json"), format!("{site}/?rest_route=")] {
        let url = format!("{rest}/");
        let mut response = match agent.get(&url).header("Accept", "application/json").call() {
            Ok(response) => response,
            Err(e) => return Err(format!("{site} can't be reached: {e}")),
        };
        let status = response.status().as_u16();
        if (300..400).contains(&status) {
            let to = response
                .headers()
                .get("location")
                .and_then(|v| v.to_str().ok())
                .unwrap_or("another address")
                .to_string();
            return Err(format!(
                "{site} redirects to {to}. Use the address the site itself answers on."
            ));
        }
        let text = response.body_mut().read_to_string().unwrap_or_default();
        let Ok(index) = serde_json::from_str::<Value>(&text) else {
            last = format!("{url} answered {status} without JSON");
            continue;
        };
        let Some(namespaces) = index["namespaces"].as_array() else {
            last = format!("{url} answered {status}, which is not WordPress's REST index");
            continue;
        };
        let authorize = index["authentication"]["application-passwords"]["endpoints"]["authorization"]
            .as_str()
            .map(str::to_owned);
        return Ok(Discovered {
            rest,
            name: index["name"].as_str().unwrap_or_default().to_string(),
            authorize,
            plugin: namespaces.iter().any(|n| n == NAMESPACE),
        });
    }
    Err(format!("{site} does not look like a WordPress site: {last}"))
}

/// Where `spacepress login` keeps credentials: `SP_CONFIG_DIR`, else `$XDG_CONFIG_HOME/sp`, else
/// `~/.config/sp`.
fn config_dir() -> Result<PathBuf, String> {
    let env = |name: &str| std::env::var_os(name).filter(|v| !v.is_empty()).map(PathBuf::from);
    env("SP_CONFIG_DIR")
        .or_else(|| env("XDG_CONFIG_HOME").map(|dir| dir.join("sp")))
        .or_else(|| env("HOME").map(|home| home.join(".config/sp")))
        .ok_or_else(|| "set HOME or SP_CONFIG_DIR so spacepress has a place to keep the site's credential".to_string())
}

fn sites_file() -> Result<PathBuf, String> {
    Ok(config_dir()?.join("sites.json"))
}

fn read_sites() -> Result<serde_json::Map<String, Value>, String> {
    let path = sites_file()?;
    match std::fs::read(&path) {
        Err(e) if e.kind() == std::io::ErrorKind::NotFound => Ok(serde_json::Map::new()),
        Err(e) => Err(format!("{}: {e}", path.display())),
        Ok(bytes) => serde_json::from_slice::<Value>(&bytes)
            .ok()
            .and_then(|v| v["sites"].as_object().cloned())
            .ok_or_else(|| {
                format!(
                    "{} is not spacepress's credential file; move it away and run spacepress login again",
                    path.display()
                )
            }),
    }
}

/// Written by rename with owner-only permissions: the file holds application passwords.
fn write_sites(sites: serde_json::Map<String, Value>) -> Result<(), String> {
    let path = sites_file()?;
    let dir = path.parent().expect("sites.json has a directory");
    std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    let mut file = tempfile::NamedTempFile::new_in(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    #[cfg(unix)]
    {
        use std::os::unix::fs::PermissionsExt as _;
        file.as_file()
            .set_permissions(std::fs::Permissions::from_mode(0o600))
            .map_err(|e| format!("{}: {e}", path.display()))?;
    }
    let text = serde_json::to_string_pretty(&json!({ "sites": sites })).expect("JSON") + "\n";
    std::io::Write::write_all(&mut file, text.as_bytes()).map_err(|e| e.to_string())?;
    file.persist(&path)
        .map_err(|e| format!("{}: {}", path.display(), e.error))?;
    Ok(())
}

/// Keep `user:application-password` for `site`, with the REST root that answered.
pub(crate) fn store(site: &str, rest: &str, user: &str, password: &str) -> Result<PathBuf, String> {
    let mut sites = read_sites()?;
    sites.insert(
        site.to_string(),
        json!({ "rest": rest, "user": user, "password": password }),
    );
    write_sites(sites)?;
    sites_file()
}

/// Forget `site`'s credential here. True when there was one.
pub(crate) fn forget(site: &str) -> Result<bool, String> {
    let mut sites = read_sites()?;
    let had = sites.remove(site).is_some();
    if had {
        write_sites(sites)?;
    }
    Ok(had)
}

const PASSWORD_ENV: &str = "SP_APP_PASSWORD";
const TOKEN_ENV: &str = "SP_TOKEN";

/// The usage line of every command that talks to a site and takes either credential.
pub(crate) const SIGN_IN: &str = "       sign in first with: spacepress login <site-url>   (in CI: SP_APP_PASSWORD=user:application-password or SP_TOKEN=<bearer token>)";

/// A credential for one site, and where it came from.
pub(crate) struct SignIn {
    pub(crate) credential: Credential,
    /// The REST root that answered `spacepress login`, when this is the credential it stored.
    pub(crate) rest: Option<String>,
}

/// The one way every remote command (push, sync, pull, export, abilities pull, doctor) gets its
/// credential for `site`. `SP_APP_PASSWORD` (`user:application-password`) or `SP_TOKEN` (a bearer
/// token from the identity plugin) in the environment is an explicit override, for CI and scripts
/// where nobody runs `spacepress login`; setting both is an error. Otherwise the credential
/// `spacepress login` stored for this site. `Ok(None)` when there is neither.
pub(crate) fn sign_in(site: &str) -> Result<Option<SignIn>, String> {
    // An address that carries a user or password is refused here, without repeating it, before any
    // message could name it.
    let key = normalize(site)?;
    let env = |name: &str| std::env::var(name).ok().filter(|s| !s.is_empty());
    match (env(PASSWORD_ENV), env(TOKEN_ENV)) {
        (Some(_), Some(_)) => return Err(format!("set {PASSWORD_ENV} or {TOKEN_ENV}, not both")),
        (Some(password), None) if !password.contains(':') => {
            return Err(format!("{PASSWORD_ENV} is user:application-password"));
        }
        (Some(password), None) => {
            return Ok(Some(SignIn {
                credential: Credential::AppPassword(password),
                rest: None,
            }));
        }
        (None, Some(token)) => {
            return Ok(Some(SignIn {
                credential: Credential::Bearer(token),
                rest: None,
            }));
        }
        (None, None) => {}
    }
    // Stored under the address `spacepress login` was given, normalized the same way.
    let sites = read_sites()?;
    let Some(entry) = sites.get(&key) else {
        return Ok(None);
    };
    let (Some(rest), Some(user), Some(password)) = (
        entry["rest"].as_str(),
        entry["user"].as_str(),
        entry["password"].as_str(),
    ) else {
        return Err(format!(
            "the stored credential for {key} is incomplete. Run: spacepress login {key}"
        ));
    };
    Ok(Some(SignIn {
        credential: Credential::AppPassword(format!("{user}:{password}")),
        rest: Some(rest.to_string()),
    }))
}

/// [`sign_in`], for a command that can't work signed out.
pub(crate) fn require(site: &str) -> Result<SignIn, String> {
    let site = normalize(site)?;
    sign_in(&site)?.ok_or_else(|| format!("not signed in to {site}. Run: spacepress login {site}"))
}

/// The site a project deploys to (`.sp/site.json`, written by `spacepress login` inside a project).
pub(crate) fn linked(project: &Path) -> Option<String> {
    let bytes = std::fs::read(project.join(".sp/site.json")).ok()?;
    serde_json::from_slice::<Value>(&bytes).ok()?["site"]
        .as_str()
        .map(str::to_owned)
}

pub(crate) fn link(project: &Path, site: &str) -> Result<(), String> {
    crate::sync::write_atomic(
        &project.join(".sp/site.json"),
        (serde_json::to_string_pretty(&json!({ "site": site })).expect("JSON") + "\n").as_bytes(),
    )
}

/// A refused or failed call to the receiver: WordPress's error code and message, and its `data`.
#[derive(Debug)]
pub(crate) struct Refused {
    pub(crate) code: String,
    pub(crate) message: String,
    pub(crate) data: Value,
}

impl std::fmt::Display for Refused {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "{}", self.message)
    }
}

/// The deploy receiver of one site, signed in.
pub(crate) struct Api {
    agent: ureq::Agent,
    rest: String,
    auth: String,
    pub(crate) site: String,
}

impl Api {
    /// `credential` is `user:application-password`.
    pub(crate) fn new(site: &str, rest: &str, credential: &str) -> Api {
        Api {
            agent: agent(600),
            rest: rest.to_string(),
            auth: Credential::AppPassword(credential.to_string()).header(),
            site: site.to_string(),
        }
    }

    /// The receiver for `site`, signed in as [`sign_in`] says. Deploying takes an application
    /// password: the plugin loads a deployment's code only for a request that signed in with one.
    pub(crate) fn connect(site: &str) -> Result<Api, String> {
        let signed = require(site)?;
        let Credential::AppPassword(password) = &signed.credential else {
            return Err(format!(
                "deploying needs an application password, not SP_TOKEN. Run: spacepress login {site}, or set {PASSWORD_ENV}=user:application-password"
            ));
        };
        let rest = match signed.rest {
            Some(rest) => rest,
            None => {
                let found = discover(site)?;
                if !found.plugin {
                    return Err(no_plugin(site));
                }
                found.rest
            }
        };
        Ok(Api::new(site, &rest, password))
    }

    /// `<rest>/spacepress-deploy/v1<path>` with query pairs, on either REST root form.
    fn url(&self, path: &str, query: &[(&str, &str)]) -> String {
        let mut url = format!("{}/{NAMESPACE}{path}", self.rest);
        let mut separator = if url.contains('?') { '&' } else { '?' };
        for (name, value) in query {
            url.push(separator);
            url.push_str(&format!("{name}={value}"));
            separator = '&';
        }
        url
    }

    pub(crate) fn get(&self, path: &str, headers: &[(&str, &str)]) -> Result<Value, Refused> {
        let mut request = self.agent.get(self.url(path, &[])).header("Authorization", &self.auth);
        for (name, value) in headers {
            request = request.header(*name, *value);
        }
        self.answer("GET", path, request.call())
    }

    pub(crate) fn post(&self, path: &str, body: &Value, headers: &[(&str, &str)]) -> Result<Value, Refused> {
        let mut request = self
            .agent
            .post(self.url(path, &[]))
            .header("Authorization", &self.auth)
            .header("Content-Type", "application/json");
        for (name, value) in headers {
            request = request.header(*name, *value);
        }
        self.answer("POST", path, request.send(body.to_string()))
    }

    pub(crate) fn upload(&self, path: &str, query: &[(&str, &str)], bytes: &[u8]) -> Result<Value, Refused> {
        let request = self
            .agent
            .post(self.url(path, query))
            .header("Authorization", &self.auth)
            .header("Content-Type", "application/octet-stream");
        self.answer("POST", path, request.send(bytes))
    }

    fn answer(
        &self,
        method: &str,
        path: &str,
        response: Result<ureq::http::Response<ureq::Body>, ureq::Error>,
    ) -> Result<Value, Refused> {
        let refused = |_status: u16, code: &str, message: String| Refused {
            code: code.to_string(),
            message,
            data: Value::Null,
        };
        let mut response =
            response.map_err(|e| refused(0, "unreachable", format!("{} can't be reached: {e}", self.site)))?;
        let status = response.status().as_u16();
        let text = response.body_mut().read_to_string().map_err(|e| {
            refused(
                status,
                "invalid_response",
                format!("{method} {path} answered {status}: {e}"),
            )
        })?;
        let parsed: Value = serde_json::from_str(&text).map_err(|_| {
            let shown: String = text.chars().take(300).collect();
            refused(
                status,
                "invalid_response",
                format!(
                    "{method} {path} on {} answered {status} without JSON: {}",
                    self.site,
                    shown.trim()
                ),
            )
        })?;
        if status >= 300 {
            let message = match (status, parsed["code"].as_str().unwrap_or("")) {
                (404, "rest_no_route") => no_plugin(&self.site),
                (401, _) | (403, "sp_forbidden") => format!(
                    "{} ({}). Sign in again: spacepress login {}",
                    parsed["message"].as_str().unwrap_or("the site refused the credential"),
                    self.site,
                    self.site
                ),
                _ => parsed["message"]
                    .as_str()
                    .map_or_else(|| format!("{method} {path} answered {status}"), str::to_owned),
            };
            return Err(Refused {
                code: parsed["code"].as_str().unwrap_or("").to_string(),
                message,
                data: parsed["data"].clone(),
            });
        }
        Ok(parsed)
    }
}

pub(crate) fn no_plugin(site: &str) -> String {
    format!(
        "{site} does not have the SpacePress plugin active. Run `spacepress plugin` to write spacepress.zip, then in wp-admin: Plugins, Add Plugin, Upload Plugin, and activate it."
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_site_is_stored_without_a_trailing_slash_and_keeps_its_path() {
        assert_eq!(normalize("https://example.test/").unwrap(), "https://example.test");
        assert_eq!(
            normalize("https://example.test/news/").unwrap(),
            "https://example.test/news"
        );
        assert_eq!(normalize("http://127.0.0.1:8905").unwrap(), "http://127.0.0.1:8905");
    }

    #[test]
    fn a_site_with_a_user_query_fragment_or_other_scheme_is_refused() {
        for site in [
            "example.test",
            "ftp://example.test",
            "https://user@example.test",
            "https://user:secret@example.test",
            "https://example.test/?page=1",
            "https://example.test/#top",
        ] {
            assert!(normalize(site).is_err(), "{site} was accepted");
        }
    }

    #[test]
    fn an_address_with_a_credential_is_refused_without_repeating_it() {
        for site in [
            "https://user:hunter2@example.test",
            "https://hunter2@example.test/path",
            "https://user:hunter2@exa mple.test",
        ] {
            let error = normalize(site).unwrap_err();
            assert!(!error.contains("hunter2"), "{error}");
        }
    }

    #[test]
    fn receiver_urls_work_on_both_forms_of_the_rest_root() {
        let pretty = Api::new("https://example.test", "https://example.test/wp-json", "u:p");
        assert_eq!(
            pretty.url("/site", &[]),
            "https://example.test/wp-json/spacepress-deploy/v1/site"
        );
        assert_eq!(
            pretty.url("/plugin", &[("sha256", "ab")]),
            "https://example.test/wp-json/spacepress-deploy/v1/plugin?sha256=ab"
        );
        // Without pretty permalinks the route is itself a query value, so further pairs follow `&`.
        let plain = Api::new("https://example.test", "https://example.test/?rest_route=", "u:p");
        assert_eq!(
            plain.url("/deployments/1/trees/app", &[("index", "0"), ("total", "2")]),
            "https://example.test/?rest_route=/spacepress-deploy/v1/deployments/1/trees/app&index=0&total=2"
        );
    }
}
