//! Build-only WordPress plugin dependencies. No PHP is evaluated here. Declaring and resolving the
//! plan is part of the compiler core; downloading, unpacking and staging the archives is `cli`.
#[cfg(feature = "cli")]
mod archive;
#[cfg(feature = "cli")]
pub mod assembly;
#[cfg(feature = "cli")]
mod patches;
pub mod version;

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::collections::{BTreeMap, BTreeSet};
use std::io;
#[cfg(feature = "cli")]
use std::io::{Read, Write};
#[cfg(feature = "cli")]
use std::path::{Path, PathBuf};
#[cfg(feature = "cli")]
use std::time::Duration;

#[cfg(feature = "cli")]
pub use assembly::{Prepared, copy_output};
pub type Result<T> = io::Result<T>;

fn invalid(message: impl Into<String>) -> io::Error {
    io::Error::other(message.into())
}

#[derive(Clone, Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Config {
    pub plugins: Vec<Plugin>,
}

#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(deny_unknown_fields, rename_all = "camelCase")]
pub struct Plugin {
    pub slug: String,
    pub version: String,
    #[serde(default)]
    pub plugin_data: Option<Resource>,
    #[serde(default)]
    pub sha256: Option<String>,
    #[serde(default)]
    pub requires: Vec<String>,
}

/// Blueprint v1 resource references. WordPress.org slugs must name an exact ZIP.
#[derive(Clone, Debug, Deserialize, Serialize, PartialEq, Eq)]
#[serde(tag = "resource", deny_unknown_fields)]
pub enum Resource {
    #[serde(rename = "wordpress.org/plugins")]
    WordPress { slug: String },
    #[serde(rename = "url")]
    Url { url: String },
}

pub struct Plan {
    /// Stable topological order (dependencies before dependants).
    pub plugins: Vec<Plugin>,
}

#[derive(Deserialize)]
#[serde(untagged)]
enum Declaration {
    Version(String),
    Detailed(Details),
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields, rename_all = "camelCase")]
struct Details {
    version: String,
    #[serde(default)]
    plugin_data: Option<Resource>,
    #[serde(default)]
    sha256: Option<String>,
    #[serde(default)]
    requires: Vec<String>,
}

/// Public package map -> existing assembler-facing plugin plan input.
pub fn parse(value: serde_json::Value) -> Result<Config> {
    let declarations: BTreeMap<String, Declaration> = serde_json::from_value(value).map_err(|e| {
        invalid(format!("plugins must be a package map, e.g. {{ \"classic-editor\": \"1.6.7\" }}; entries are exact version strings or {{version, pluginData?, sha256?, requires?}} objects; Blueprint steps and activation are unsupported: {e}"))
    })?;
    let plugins = declarations
        .into_iter()
        .map(|(slug, declaration)| {
            let details = match declaration {
                Declaration::Version(version) => Details {
                    version,
                    plugin_data: None,
                    sha256: None,
                    requires: Vec::new(),
                },
                Declaration::Detailed(details) => details,
            };
            Plugin {
                slug,
                version: details.version,
                plugin_data: details.plugin_data,
                sha256: details.sha256,
                requires: details.requires,
            }
        })
        .collect();
    Ok(Config { plugins })
}

fn slug(s: &str) -> bool {
    !s.is_empty()
        && s.len() <= 100
        && s.split('-')
            .all(|part| !part.is_empty() && part.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit()))
}

#[cfg(feature = "cli")]
impl Resource {
    fn url(&self) -> String {
        match self {
            Self::WordPress { slug } => format!("https://downloads.wordpress.org/plugin/{slug}"),
            Self::Url { url } => url.clone(),
        }
    }
}

impl Plugin {
    fn resource(&self) -> Resource {
        self.plugin_data.clone().unwrap_or_else(|| Resource::WordPress {
            slug: format!("{}.{}.zip", self.slug, self.version),
        })
    }

    fn validate(&self) -> Result<()> {
        if !slug(&self.slug) || self.slug == "zero" || self.slug.starts_with("zero-") {
            return Err(invalid(format!(
                "invalid or reserved plugin slug `{}` (zero-* belongs to generated plugins)",
                self.slug
            )));
        }
        if !version::valid(&self.version) {
            return Err(invalid(format!(
                "plugin {} needs an exact numeric version (with optional WordPress suffix)",
                self.slug
            )));
        }
        if self.sha256.as_ref().is_some_and(|sha| {
            sha.len() != 64 || !sha.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b))
        }) {
            return Err(invalid(format!(
                "plugin {} needs a lowercase 64-character sha256 pin",
                self.slug
            )));
        }
        match self.plugin_data.as_ref() {
            Some(Resource::WordPress { slug }) if slug != &format!("{}.{}.zip", self.slug, self.version) => {
                return Err(invalid(format!(
                    "plugin {}: wordpress.org/plugins slug must be `{}.{}.zip` (floating releases are unsupported)",
                    self.slug, self.slug, self.version
                )));
            }
            Some(Resource::Url { url }) => {
                if self.sha256.is_none() {
                    return Err(invalid(format!("URL plugin {} requires an authored sha256", self.slug)));
                }
                let u = url::Url::parse(url).map_err(|e| invalid(format!("plugin {}: invalid URL: {e}", self.slug)))?;
                let loopback = matches!(u.host_str(), Some("127.0.0.1" | "[::1]" | "localhost"));
                if !(u.scheme() == "https" || (u.scheme() == "http" && loopback))
                    || u.host_str().is_none()
                    || !u.username().is_empty()
                    || u.password().is_some()
                    || u.fragment().is_some()
                {
                    return Err(invalid(format!(
                        "plugin {} URL must use HTTPS (HTTP only on loopback), without credentials or fragments",
                        self.slug
                    )));
                }
            }
            _ => {}
        }
        let mut seen = BTreeSet::new();
        for dep in &self.requires {
            if !slug(dep) || !seen.insert(dep) {
                return Err(invalid(format!(
                    "plugin {}: invalid or duplicate dependency `{dep}`",
                    self.slug
                )));
            }
        }
        Ok(())
    }
}

/// A3 supplies platform pins here too; user overrides must meet each platform floor.
/// Resolution never consults WordPress.org metadata or silently downloads transitive dependencies.
pub fn resolve(config: &Config, required: &[Plugin]) -> Result<Plan> {
    let mut plugins = BTreeMap::new();
    for p in required {
        p.validate()?;
        if plugins.insert(p.slug.clone(), p.clone()).is_some() {
            return Err(invalid(format!("duplicate platform plugin `{}`", p.slug)));
        }
    }
    let mut declared = BTreeSet::new();
    for p in &config.plugins {
        p.validate()?;
        if !declared.insert(p.slug.clone()) {
            return Err(invalid(format!("duplicate plugin declaration `{}`", p.slug)));
        }
        let mut entry = p.clone();
        if let Some(platform) = plugins.get(&p.slug) {
            if p.version == platform.version
                && p.resource() == platform.resource()
                && p.sha256
                    .as_ref()
                    .zip(platform.sha256.as_ref())
                    .is_some_and(|(a, b)| a != b)
            {
                return Err(invalid(format!(
                    "plugin {}: conflicting checksum for the required platform pin",
                    p.slug
                )));
            }
            if p.version == platform.version && p.resource() == platform.resource() && entry.sha256.is_none() {
                entry.sha256 = platform.sha256.clone();
            }
            for dep in &platform.requires {
                if !entry.requires.contains(dep) {
                    entry.requires.push(dep.clone());
                }
            }
        }
        plugins.insert(p.slug.clone(), entry);
    }
    for p in required {
        if !version::at_least(&plugins[&p.slug].version, &p.version) {
            return Err(invalid(format!("platform plugin {} requires >= {}", p.slug, p.version)));
        }
    }
    fn visit(
        name: &str,
        plugins: &BTreeMap<String, Plugin>,
        visiting: &mut BTreeSet<String>,
        done: &mut BTreeSet<String>,
        out: &mut Vec<Plugin>,
    ) -> Result<()> {
        if done.contains(name) {
            return Ok(());
        }
        if !visiting.insert(name.to_string()) {
            return Err(invalid(format!("plugin dependency cycle at `{name}`")));
        }
        let p = plugins.get(name).ok_or_else(|| {
            invalid(format!(
                "undeclared plugin dependency `{name}`; declare its exact version and sha256 in plugins"
            ))
        })?;
        let mut deps = p.requires.clone();
        deps.sort();
        for dep in deps {
            visit(&dep, plugins, visiting, done, out)?;
        }
        visiting.remove(name);
        done.insert(name.to_string());
        out.push(p.clone());
        Ok(())
    }
    let mut plan = Plan { plugins: Vec::new() };
    let mut visiting = BTreeSet::new();
    let mut done = BTreeSet::new();
    for name in plugins.keys() {
        visit(name, &plugins, &mut visiting, &mut done, &mut plan.plugins)?;
    }
    Ok(plan)
}

/// `supported.json`: the plugins and version ranges the Zero team tests.
#[derive(Deserialize)]
struct Supported {
    plugins: BTreeMap<String, Range>,
}

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Range {
    min: String,
    below: Option<String>,
}

fn supported() -> Supported {
    serde_json::from_str(include_str!("supported.json")).expect("src/wp_plugins/supported.json is valid")
}

const ISSUES: &str = "https://github.com/spacefast/zero/issues";

/// One warning per declared plugin outside `supported.json`. Zero still installs it: this is advice,
/// not a refusal, because Zero does not support every WordPress plugin.
pub fn unsupported(config: &Config) -> Vec<String> {
    let supported = supported();
    config
        .plugins
        .iter()
        .filter_map(|p| {
            let reason = match supported.plugins.get(&p.slug) {
                None => format!("plugin {} {} is not supported by the Zero team", p.slug, p.version),
                Some(r)
                    if !version::at_least(&p.version, &r.min)
                        || r.below.as_ref().is_some_and(|below| version::at_least(&p.version, below)) =>
                {
                    let range = match &r.below {
                        Some(below) => format!(">= {}, < {below}", r.min),
                        None => format!(">= {}", r.min),
                    };
                    format!(
                        "plugin {} {} is outside the version range the Zero team supports ({range})",
                        p.slug, p.version
                    )
                }
                Some(_) => return None,
            };
            Some(format!(
                "{reason}. Zero installs it, but doesn't test it; Zero doesn't support every WordPress plugin. To ask for support, open an issue: {ISSUES}"
            ))
        })
        .collect()
}

pub fn digest(bytes: &[u8]) -> String {
    hex(&Sha256::digest(bytes))
}

#[cfg(feature = "cli")]
const MAX_ZIP: u64 = 64 * 1024 * 1024;

#[cfg(feature = "cli")]
fn acquire(plugin: &Plugin, pin: Option<&str>, cache: &Path, url: &str) -> Result<(PathBuf, String)> {
    std::fs::create_dir_all(cache)?;
    if let Some(pin) = pin {
        let path = cache.join(format!("{pin}.zip"));
        if std::fs::symlink_metadata(&path).is_ok() {
            let meta = std::fs::symlink_metadata(&path)?;
            if !meta.is_file() || meta.len() > MAX_ZIP || digest(&std::fs::read(&path)?) != pin {
                return Err(invalid(format!(
                    "plugin {}: corrupt or unsafe cache entry; remove {} and rebuild",
                    plugin.slug,
                    path.display()
                )));
            }
            return Ok((path, pin.to_string()));
        }
    }
    let agent: ureq::Agent = ureq::Agent::config_builder()
        .timeout_global(Some(Duration::from_secs(60)))
        .https_only(url.starts_with("https:"))
        .max_redirects(5)
        .build()
        .into();
    let mut response = agent
        .get(url)
        .call()
        .map_err(|e| invalid(format!("plugin {}: download failed: {e}", plugin.slug)))?;
    let mut file = tempfile::NamedTempFile::new_in(cache)?;
    let copied = io::copy(&mut response.body_mut().as_reader().take(MAX_ZIP + 1), &mut file)?;
    if copied > MAX_ZIP {
        return Err(invalid(format!("plugin {} archive exceeds 64 MiB", plugin.slug)));
    }
    file.flush()?;
    let sha = digest(&std::fs::read(file.path())?);
    if pin.is_some_and(|pin| pin != sha) {
        return Err(invalid(format!("plugin {}: sha256 mismatch", plugin.slug)));
    }
    let path = cache.join(format!("{sha}.zip"));
    file.persist(&path).map_err(|e| e.error)?;
    Ok((path, sha))
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unsupported_warns_outside_the_manifest_and_its_ranges() {
        let config = parse(serde_json::json!({
            "classic-editor": "1.6.7",
            "polylang": "4.0",
            "secure-custom-fields": "6.9.6",
            "hello-dolly": "1.7.2",
        }))
        .unwrap();
        let warnings = unsupported(&config);
        assert_eq!(warnings.len(), 3, "{warnings:#?}");
        assert!(warnings[0].starts_with("plugin hello-dolly 1.7.2 is not supported by the Zero team."));
        assert!(
            warnings[1]
                .starts_with("plugin polylang 4.0 is outside the version range the Zero team supports (>= 3.9, < 4).")
        );
        assert!(warnings[2].starts_with("plugin secure-custom-fields 6.9.6 is outside"));
        assert!(warnings.iter().all(|w| w.ends_with(ISSUES)));
    }

    /// Zero installs its platform pins itself, so they must sit inside the ranges it claims to support.
    #[test]
    fn platform_pins_are_supported() {
        let plugins = crate::platform::plugins(true);
        assert_eq!(unsupported(&Config { plugins }), Vec::<String>::new());
    }
}
