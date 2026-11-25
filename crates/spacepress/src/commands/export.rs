//! `spacepress export [dir] [--out dir] [--site url] [--base /path] [--site-url https://…]`: the app's
//! `sp/app/` pages as a static site that needs no WordPress to serve it
//! (docs/decisions/2026-11-13-static-output.md).
//!
//! The project must say `output: "static"`. Pages are rendered by a WordPress running the build:
//! - without `--site`, a throwaway WordPress on SQLite (the Studio runner `spacepress dev` uses), with the
//!   build's plugins and `sp/content/` seeded as a publish seeds a Space; it is removed after;
//! - with `--site`, the hosted site, which must run this checkout's build, signed in as an editor
//!   (`remote::sign_in`: the credential `spacepress login` stored, or `SP_APP_PASSWORD` / `SP_TOKEN`).
//!
//! Each URL the site lists (`GET /wp-json/spacepress/v1/prerender`) is fetched as a signed-out visitor with
//! its prerender token, with its Markdown twin. The export writes `<path>.html` and `<path>.md`,
//! `404.html`, `sitemap.xml`, `llms.txt`, `robots.txt`, `_redirects` with a refresh page at each old
//! URL, and every same-site file the pages reference (stylesheets, images, script modules including
//! the Interactivity API runtime) at the path WordPress serves it from. Same-site URLs become
//! root-relative, under `--base`.
//!
//! A page that renders a link to a same-site URL the export does not contain (a page rendered per
//! request, the sign-in or comment form, the REST API) is refused, with the page and the fix: the
//! link would 404 on the static host. With `--site`, sign-in, comment and action URLs stay on the
//! hosted site and keep working there.

use crate::sync::client::{Client, Credential};
use serde_json::Value;
use std::collections::{BTreeMap, BTreeSet};
use std::io::{BufRead, Write};
use std::path::{Path, PathBuf};
use std::process::{Child, ExitCode, Stdio};
use std::sync::LazyLock;
use std::time::Duration;

/// The patterns the export runs over every page, compiled once.
fn pattern(source: &str) -> regex::Regex {
    regex::Regex::new(source).expect("export pattern")
}
static URL_ATTRIBUTE: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?i)\b(href|src|action|content)=("([^"]*)"|'([^']*)')"#));
static SRCSET: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?i)\b(srcset|imagesrcset)=("([^"]*)"|'([^']*)')"#));
static CANONICAL: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?i)<link[^>]*\brel=["']canonical["'][^>]*>\s*"#));
static META_URL: LazyLock<regex::Regex> = LazyLock::new(|| {
    pattern(
        r#"(?i)<meta[^>]*\b(?:property|name)=["'](?:og:url|og:image|og:image:url|og:image:secure_url|twitter:image|twitter:url)["'][^>]*>\s*"#,
    )
});
static CONTENT: LazyLock<regex::Regex> = LazyLock::new(|| pattern(r#"(?i)\bcontent=("([^"]*)"|'([^']*)')"#));
static STYLE: LazyLock<regex::Regex> = LazyLock::new(|| pattern(r#"(?s)(<style[^>]*>)(.*?)(</style>)"#));
static IMPORT_MAP: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?s)(<script[^>]*type="importmap"[^>]*>)(.*?)(</script>)"#));
// A script module's data, whatever other attributes a plugin adds to the tag (`wp_inline_script_attributes`).
static MODULE_DATA: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?s)(<script\b[^>]*\bid="wp-script-module-data-([^"]+)"[^>]*>)(.*?)(</script>)"#));
static CSS_REFERENCE: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"url\(\s*['"]?([^'")]+)['"]?\s*\)|@import\s+['"]([^'"]+)['"]"#));
static NOINDEX: LazyLock<regex::Regex> =
    LazyLock::new(|| pattern(r#"(?i)<meta name=['"]robots['"] content=['"][^'"]*noindex"#));

const USAGE: &str = "usage: spacepress export [dir] [--out dir] [--site url] [--base /path] [--site-url https://example.com]   (--site: as an editor)";

/// WordPress endpoints a page may link or post to that stay on the hosted site with `--site`.
const HOSTED: &[&str] = &["/wp-login.php", "/wp-comments-post.php", "/wp-admin", "/identity"];

/// The default output, below the project.
pub const OUT: &str = "sp/dist/static";

struct Flags {
    project: PathBuf,
    out: Option<PathBuf>,
    site: Option<String>,
    base: String,
    site_url: Option<String>,
}

fn parse(args: &[String]) -> Result<Flags, String> {
    let mut flags = Flags {
        project: PathBuf::from("."),
        out: None,
        site: None,
        base: String::new(),
        site_url: None,
    };
    let mut it = args.iter();
    while let Some(a) = it.next() {
        let mut value = |name: &str| it.next().cloned().ok_or(format!("{name} needs a value\n{USAGE}"));
        match a.as_str() {
            "--out" => flags.out = Some(PathBuf::from(value("--out")?)),
            "--site" => flags.site = Some(value("--site")?),
            "--base" => {
                let base = value("--base")?;
                let trimmed = base.trim_matches('/');
                if !trimmed
                    .split('/')
                    .all(|s| !s.is_empty() && s.chars().all(|c| c.is_ascii_alphanumeric() || "-._~".contains(c)))
                    && !trimmed.is_empty()
                {
                    return Err(format!("--base is a URL path like /docs, not {base}"));
                }
                flags.base = if trimmed.is_empty() {
                    String::new()
                } else {
                    format!("/{trimmed}")
                };
            }
            "--site-url" => {
                let url = value("--site-url")?;
                let parsed = url::Url::parse(&url).map_err(|e| format!("--site-url: {e}"))?;
                if !matches!(parsed.scheme(), "http" | "https") || parsed.query().is_some() {
                    return Err("--site-url is the address the export is served at, like https://example.com".into());
                }
                flags.site_url = Some(url.trim_end_matches('/').to_string());
            }
            other if other.starts_with('-') => return Err(format!("unknown option {other}\n{USAGE}")),
            other => flags.project = PathBuf::from(other),
        }
    }
    Ok(flags)
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    let flags = match parse(args) {
        Ok(flags) => flags,
        Err(e) => {
            eprintln!("{e}");
            return ExitCode::from(2);
        }
    };
    match run(flags) {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("error: {e}");
            ExitCode::from(1)
        }
    }
}

fn run(flags: Flags) -> Result<(), String> {
    let started = std::time::Instant::now();
    let project = flags
        .project
        .canonicalize()
        .map_err(|e| format!("{}: {e}", flags.project.display()))?;
    let _frontend = crate::frontend::prepare_styles(&project)
        .map_err(|d| crate::diag::render(&d, &project).trim_end().to_string())?;
    let built = super::build::build_site(&project, super::build::frozen(false))
        .map_err(|d| crate::diag::render(&d, &project).trim_end().to_string())?;
    if built.schema.output != crate::schema::Output::Static {
        return Err(
            "spacepress export writes a site with no WordPress, and this app renders pages on WordPress: set `output: \"static\"` in spacepress.config.ts. To prebuild some pages on a WordPress site, export `prerender = true` from them instead"
                .into(),
        );
    }
    if built.schema.app.as_ref().is_none_or(|a| a.routes.is_empty()) {
        return Err("spacepress export renders sp/app/, and this project has no pages: add sp/app/page.tsx".into());
    }
    let out = output_dir(&project, flags.out.as_deref())?;
    // The build the pages render from. Local: this checkout's, written as `spacepress build` writes it.
    let (site, credential, _local) = match &flags.site {
        Some(site) => {
            let site = site.trim_end_matches('/').to_string();
            let credential = super::remote::require(&site)
                .map_err(|e| format!("{e}\n{USAGE}\n{}", super::remote::SIGN_IN))?
                .credential;
            (site, credential, None)
        }
        None => {
            super::build::emit_project(&project, &built).map_err(|e| format!("couldn't write sp/_generated: {e}"))?;
            let content = super::build::emit_wp_content(&project, &project.join(crate::layout::WP_CONTENT), &built)
                .map_err(|e| format!("couldn't write wp-content: {e}"))?;
            let seeds = crate::bundle::seed_records(&crate::loader::Disk, &project, &built.schema, true)
                .map_err(|d| crate::diag::render(&d, &project).trim_end().to_string())?;
            let local = Local::start(&content, &seeds)?;
            let site = local.url.clone();
            let credential = Credential::AppPassword(local.credential.clone());
            (site, credential, Some(local))
        }
    };
    let client = Client::new(&site, credential)?;
    let listing = client.get("/prerender").map_err(|p| match p.status {
        404 => format!("{site}: no sp/app/ pages to export ({})", p.message()),
        401 | 403 => format!(
            "{site}: {}; the credential must be an editor's or an administrator's",
            p.message()
        ),
        _ => format!("{site}: {}", p.message()),
    })?;
    if flags.site.is_some() {
        // The checkout's compiled app plugin, hashed as the site hashes its own (Prebuilt::appHash):
        // every file below sp/app/, so a change to any page, component or action counts.
        let public = crate::emit::public_files(&project).map_err(|e| format!("couldn't read public/: {e}"))?;
        let (_, packaged) = crate::app_plugin::package(&built, Vec::new(), public);
        let mut files: Vec<(String, Vec<u8>)> = packaged
            .into_iter()
            .filter_map(|(path, bytes)| {
                let rest = path.split_once('/')?.1.strip_prefix("sp/app/")?;
                // Migrations are the site's ledger, not code a page renders with.
                (!rest.starts_with("migrations/")).then(|| (rest.to_string(), bytes))
            })
            .collect();
        files.sort();
        let hash = {
            use sha2::Digest;
            let mut hash = sha2::Sha256::new();
            for (path, bytes) in &files {
                hash.update(path.as_bytes());
                hash.update([0]);
                hash.update(bytes);
                hash.update([0]);
            }
            crate::hex(hash.finalize())
        };
        if listing["app"].as_str() != Some(hash.as_str()) {
            return Err(format!(
                "{site} runs another build of this app: export renders the checkout's pages, so deploy this checkout to the site first (or check out what the site runs)"
            ));
        }
    }
    let export = Export::new(&site, &flags, &built.schema, &listing)?;
    let report = export.write(&out)?;
    println!(
        "exported {} pages, {} files and {} redirects -> {} in {:.0?}",
        report.pages,
        report.files,
        report.redirects,
        out.strip_prefix(&project).unwrap_or(&out).display(),
        started.elapsed()
    );
    Ok(())
}

// ------------------------------------------------------------------ the local site

/// The throwaway WordPress `scripts/dev/studio.mjs export` runs; stopped and removed on drop.
struct Local {
    child: Child,
    url: String,
    credential: String,
}

impl Local {
    fn start(content: &Path, seeds: &[(String, Value)]) -> Result<Local, String> {
        let work = tempfile::tempdir().map_err(|e| e.to_string())?;
        let seed = work.path().join("seed");
        std::fs::create_dir(&seed).map_err(|e| e.to_string())?;
        for (name, records) in seeds {
            std::fs::write(seed.join(format!("{name}.json")), records.to_string()).map_err(|e| e.to_string())?;
        }
        let mut command = super::dev::export_command()?;
        command
            .arg(content)
            .arg(&seed)
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());
        // Its own process group: a Ctrl-C in the terminal stops `spacepress` only. The runner then reads
        // the end of its standard input, which closes whenever `spacepress` exits for any reason, and
        // removes the site, as on a normal exit.
        #[cfg(unix)]
        std::os::unix::process::CommandExt::process_group(&mut command, 0);
        eprintln!("starting a local WordPress for the export…");
        let mut child = command
            .spawn()
            .map_err(|e| format!("spacepress export needs Node.js 22+ and npm (npx): {e}"))?;
        let stdout = child.stdout.take().expect("piped");
        let mut line = String::new();
        std::io::BufReader::new(stdout)
            .read_line(&mut line)
            .map_err(|e| e.to_string())?;
        let ready: Value = match serde_json::from_str(&line) {
            Ok(v) => v,
            Err(_) => {
                let _ = child.kill();
                let mut err = String::new();
                if let Some(mut stderr) = child.stderr.take() {
                    let _ = std::io::Read::read_to_string(&mut stderr, &mut err);
                }
                return Err(format!("the local WordPress didn't start:\n{line}{err}"));
            }
        };
        // The seed directory was copied into the site; the temp directory goes now.
        drop(work);
        Ok(Local {
            url: ready["url"].as_str().unwrap_or_default().to_string(),
            credential: ready["credential"].as_str().unwrap_or_default().to_string(),
            child,
        })
    }
}

impl Drop for Local {
    fn drop(&mut self) {
        // Closing standard input tells the runner to stop and remove the site.
        drop(self.child.stdin.take());
        let _ = self.child.wait();
    }
}

// ------------------------------------------------------------------ fetching and writing

/// A response: status, lowercase header names to values, and the body.
type Fetched = (u16, BTreeMap<String, String>, Vec<u8>);

struct Page {
    path: String,
    html: String,
    markdown: Option<String>,
}

struct Report {
    pages: usize,
    files: usize,
    redirects: usize,
}

/// The exported file paths and optional queries used to fetch their contents.
type Assets = BTreeMap<String, String>;
type AssetRequests = BTreeSet<(String, String)>;

struct Export<'a> {
    agent: ureq::Agent,
    /// The rendering site: scheme, host and port, and its home path.
    origin: String,
    home: String,
    flags: &'a Flags,
    listing: &'a Value,
    name: String,
    /// Public actions by name: a client component may call them on `--site`.
    public_actions: BTreeMap<String, bool>,
}

impl<'a> Export<'a> {
    fn new(site: &str, flags: &'a Flags, schema: &crate::schema::Schema, listing: &'a Value) -> Result<Self, String> {
        // Every page and file is fetched from the site that was asked: a listing naming another
        // origin as its home is refused, not followed.
        let asked = url::Url::parse(site).map_err(|e| format!("{site}: {e}"))?;
        let home = url::Url::parse(listing["home"].as_str().unwrap_or(site)).map_err(|e| e.to_string())?;
        let origin = home.origin().ascii_serialization();
        if origin != asked.origin().ascii_serialization() || !home.username().is_empty() {
            return Err(format!(
                "{site} says its pages are at {home}, another origin; export from the site's own address"
            ));
        }
        let agent: ureq::Agent = ureq::Agent::config_builder()
            .timeout_global(Some(Duration::from_secs(120)))
            .http_status_as_error(false)
            .max_redirects(0)
            .build()
            .into();
        Ok(Export {
            agent,
            origin,
            home: home.path().trim_end_matches('/').to_string(),
            flags,
            listing,
            name: crate::schema::title_case(&schema.name),
            public_actions: listing["actions"]
                .as_object()
                .map(|m| m.iter().map(|(k, v)| (k.clone(), v == true)).collect())
                .unwrap_or_default(),
        })
    }

    /// GET a path below the site's origin, with a prerender token when given: (status, headers, body).
    fn get(&self, path: &str, token: Option<&str>) -> Result<Fetched, String> {
        let url = format!("{}{path}", self.origin);
        let mut request = self.agent.get(&url).header("X-SpacePress-Export", "1");
        if let Some(token) = token {
            request = request.header("X-SpacePress-Prerender", token);
        }
        let mut response = request.call().map_err(|e| format!("GET {url}: {e}"))?;
        let status = response.status().as_u16();
        let headers = response
            .headers()
            .iter()
            .map(|(k, v)| {
                (
                    k.as_str().to_ascii_lowercase(),
                    v.to_str().unwrap_or_default().to_string(),
                )
            })
            .collect();
        let body = response
            .body_mut()
            .with_config()
            .limit(64 * 1024 * 1024)
            .read_to_vec()
            .map_err(|e| format!("GET {url}: {e}"))?;
        Ok((status, headers, body))
    }

    fn pages(&self) -> Result<Vec<Page>, String> {
        let mut pages = Vec::new();
        for url in self.listing["urls"].as_array().into_iter().flatten() {
            let path = url["path"].as_str().unwrap_or("/").to_string();
            let route = url["route"].as_str().unwrap_or_default();
            let full = format!("{}{}", self.home, if path == "/" { "/" } else { &path });
            let (status, headers, body) = self.get(&full, url["token"].as_str())?;
            if status == 200 && headers.get("x-spacepress-prerendered").map(String::as_str) != Some("1") {
                return Err(format!(
                    "{path}: the answer wasn't rendered for the export (a cache in front of {}?). Export from an address that reaches WordPress directly",
                    self.origin
                ));
            }
            if let Some(function) = headers.get("x-spacepress-prerender-refused") {
                return Err(format!(
                    "{path} (app/{route}): the page calls `{function}`, which asks who the visitor is, and a static page is one file for everyone. Move that into a client component, or leave it out"
                ));
            }
            match status {
                200 => {}
                301 | 302 | 303 | 307 | 308 => {
                    return Err(format!(
                        "{path} (app/{route}) redirects to {}; a static page can't redirect. Link to the destination instead, or remove the redirect()",
                        headers.get("location").map(String::as_str).unwrap_or("?")
                    ));
                }
                404 => {
                    return Err(format!(
                        "{path} (app/{route}) is listed but renders a 404: its staticParams lists a value the page calls notFound() for. Leave that value out of staticParams"
                    ));
                }
                status => {
                    return Err(format!(
                        "{path} (app/{route}) failed to render (HTTP {status}); the site's error log has the cause"
                    ));
                }
            }
            let twin = &url["twin"];
            let markdown = match twin["path"].as_str() {
                Some(twin_path) => {
                    let (status, headers, body) = self.get(twin_path, twin["token"].as_str())?;
                    (status == 200 && headers.get("x-spacepress-prerendered").map(String::as_str) == Some("1"))
                        .then(|| String::from_utf8_lossy(&body).into_owned())
                }
                None => None,
            };
            pages.push(Page {
                path,
                html: String::from_utf8_lossy(&body).into_owned(),
                markdown,
            });
        }
        Ok(pages)
    }

    fn write(&self, out: &Path) -> Result<Report, String> {
        let pages = self.pages()?;
        let exported: BTreeSet<String> = pages.iter().map(|p| p.path.clone()).collect();
        let nf = &self.listing["notFound"];
        let (status, _, not_found) =
            self.get(nf["path"].as_str().unwrap_or("/__sp-not-found"), nf["token"].as_str())?;
        if status != 404 {
            return Err(format!(
                "the not-found page answered {status}, not 404; not-found.tsx (or the error it hit) needs fixing before 404.html can be written"
            ));
        }
        let not_found = String::from_utf8_lossy(&not_found).into_owned();
        let redirects: Vec<(String, String)> = self.listing["redirects"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|r| Some((r["from"].as_str()?.to_string(), r["to"].as_str()?.to_string())))
            .filter(|(from, _)| !exported.contains(from))
            .collect();
        let moved: BTreeSet<String> = redirects.iter().map(|(from, _)| from.clone()).collect();
        // Write into a fresh directory beside the output, then swap it in: a failed export leaves
        // the last good one.
        let parent = out.parent().ok_or("--out needs a parent directory")?;
        std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
        let stage = tempfile::Builder::new()
            .prefix(".spacepress-export-")
            .tempdir_in(parent)
            .map_err(|e| e.to_string())?;
        let mut assets = Assets::new();
        let mut refusals = Vec::new();
        for page in &pages {
            let mut html = self.rewrite(&page.html, &page.path, &exported, &moved, &mut assets, &mut refusals);
            // With --site-url, a page names its own address: the one the export is served at.
            if self.flags.site_url.is_some() && !html.contains("rel=\"canonical\"") {
                let link = format!(
                    "<link rel=\"canonical\" href=\"{}\">\n",
                    xml(&self.absolute(&page.path))
                );
                html = html.replacen("</head>", &format!("{link}</head>"), 1);
            }
            write(&stage.path().join(file_of(&page.path, "html")?), html.as_bytes())?;
            if let Some(md) = &page.markdown {
                write(
                    &stage.path().join(file_of(&page.path, "md")?),
                    self.rewrite_markdown(md).as_bytes(),
                )?;
            }
        }
        let html = self.rewrite(&not_found, "/404", &exported, &moved, &mut assets, &mut refusals);
        write(&stage.path().join("404.html"), html.as_bytes())?;
        if !refusals.is_empty() {
            refusals.sort();
            refusals.dedup();
            return Err(format!(
                "the export would link to URLs a static site doesn't have:\n{}",
                refusals.iter().map(|r| format!("  {r}")).collect::<Vec<_>>().join("\n")
            ));
        }
        // Assets, and what they reference in turn (a stylesheet's fonts and images, a module's imports).
        let mut files = 0;
        let mut queue: Vec<_> = assets.into_iter().collect();
        let mut seen: AssetRequests = queue.iter().cloned().collect();
        let mut written = BTreeSet::new();
        while let Some((path, query)) = queue.pop() {
            let (status, _, body) = self.get(&format!("{}{path}{query}", self.home), None)?;
            if status != 200 {
                return Err(format!(
                    "{path}{query} answered {status}; the pages reference it, so the export needs it"
                ));
            }
            let output = stage.path().join(asset_file(&path)?);
            let mut body = body;
            if path.ends_with(".css") || crate::assets::is_javascript(&path) {
                let text = String::from_utf8_lossy(&body);
                let (rewritten, references) = if path.ends_with(".css") {
                    let references = self
                        .referenced_styles(&text, &path)
                        .into_iter()
                        .map(|path| (path, String::new()))
                        .collect();
                    (self.relink(&text), references)
                } else {
                    self.rewrite_module(&text, &path)?
                };
                for dependency in references {
                    if seen.insert(dependency.clone()) {
                        queue.push(dependency);
                    }
                }
                body = rewritten.into_bytes();
            }
            if written.insert(output.clone()) {
                write(&output, &body)?;
                files += 1;
            } else if std::fs::read(&output).map_err(|e| format!("{}: {e}", output.display()))? != body {
                return Err(format!(
                    "{path}: different asset queries return different content; use distinct paths for static export"
                ));
            }
        }
        // Redirects: a `_redirects` file, and a refresh page at each old URL for hosts without rules.
        let mut rules = String::new();
        for (from, to) in &redirects {
            rules.push_str(&format!("{}{from} {}{to} 301\n", self.flags.base, self.flags.base));
            let target = format!("{}{to}", self.flags.base);
            let page = format!(
                "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"><title>Moved</title><link rel=\"canonical\" href=\"{}\"><meta http-equiv=\"refresh\" content=\"0; url={target}\"></head><body><p><a href=\"{target}\">This page has moved.</a></p></body></html>\n",
                self.absolute(to)
            );
            write(&stage.path().join(file_of(from, "html")?), page.as_bytes())?;
        }
        if !rules.is_empty() {
            write(&stage.path().join("_redirects"), rules.as_bytes())?;
        }
        // A sitemap lists absolute URLs (sitemaps.org), so it needs to know where the site is served.
        let mut robots = String::from("User-agent: *\nAllow: /\n");
        if self.flags.site_url.is_some() {
            write(&stage.path().join("sitemap.xml"), self.sitemap(&pages).as_bytes())?;
            robots.push_str(&format!("\nSitemap: {}\n", self.absolute("/sitemap.xml")));
        } else {
            eprintln!("note: no sitemap.xml; pass --site-url https://… (where the export is served) to write one");
        }
        write(&stage.path().join("llms.txt"), self.llms(&pages).as_bytes())?;
        write(&stage.path().join("robots.txt"), robots.as_bytes())?;
        // No written file may still point at the WordPress that rendered it. With --site, that
        // WordPress is the hosted site the pages call on purpose (actions, sign-in, comments), so
        // the check is for a local one, a throwaway site that is gone once the export is written.
        let mut leftovers = Vec::new();
        if self.flags.site.is_none() {
            leftover(stage.path(), stage.path(), &self.origin, &mut leftovers);
        }
        if !leftovers.is_empty() {
            leftovers.sort();
            return Err(format!(
                "these files still name {}, which the static site won't have; the URL is in a place the export doesn't rewrite:\n{}",
                self.origin,
                leftovers
                    .iter()
                    .take(20)
                    .map(|f| format!("  {f}"))
                    .collect::<Vec<_>>()
                    .join("\n")
            ));
        }
        write(
            &stage.path().join(MARKER),
            b"written by spacepress export; spacepress export replaces this folder\n",
        )?;
        let stage = stage.keep();
        if out.exists() {
            let old = out.with_extension(format!("old-{}", std::process::id()));
            std::fs::rename(out, &old).map_err(|e| e.to_string())?;
            if let Err(e) = std::fs::rename(&stage, out) {
                // The new export couldn't take its place: the previous one goes back.
                let _ = std::fs::rename(&old, out);
                let _ = std::fs::remove_dir_all(&stage);
                return Err(format!("couldn't put the export at {}: {e}", out.display()));
            }
            let _ = std::fs::remove_dir_all(old);
        } else if let Err(e) = std::fs::rename(&stage, out) {
            let _ = std::fs::remove_dir_all(&stage);
            return Err(format!("couldn't put the export at {}: {e}", out.display()));
        }
        Ok(Report {
            pages: pages.len(),
            files,
            redirects: redirects.len(),
        })
    }

    /// The URL a path is served at: `--site-url` when given, else root-relative under `--base`.
    fn absolute(&self, path: &str) -> String {
        format!(
            "{}{}{path}",
            self.flags.site_url.as_deref().unwrap_or(""),
            self.flags.base
        )
    }

    /// A same-site URL as a path below the home path (with its query and fragment), or None for any
    /// other URL. Same-site is the rendering site's origin exactly (scheme, host, port, no userinfo)
    /// and a path at or below its home path, on a segment boundary.
    fn local_path(&self, url: &str) -> Option<String> {
        let full = if url.starts_with('/') && !url.starts_with("//") {
            format!("{}{url}", self.origin)
        } else {
            url.to_string()
        };
        let parsed = url::Url::parse(&full).ok()?;
        if parsed.origin().ascii_serialization() != self.origin
            || !parsed.username().is_empty()
            || parsed.password().is_some()
        {
            return None;
        }
        let path = parsed.path();
        let rest = if self.home.is_empty() {
            path
        } else if path == self.home {
            ""
        } else {
            path.strip_prefix(&self.home).filter(|r| r.starts_with('/'))?
        };
        let mut out = if rest.is_empty() {
            "/".to_string()
        } else {
            rest.to_string()
        };
        if let Some(q) = parsed.query() {
            out.push('?');
            out.push_str(q);
        }
        if let Some(f) = parsed.fragment() {
            out.push('#');
            out.push_str(f);
        }
        Some(out)
    }

    /// Rewrites a page: same-site URLs become root-relative under `--base`, files go on the asset
    /// list, and a link to a same-site page the export doesn't contain is a refusal.
    fn rewrite(
        &self,
        html: &str,
        page: &str,
        exported: &BTreeSet<String>,
        moved: &BTreeSet<String>,
        assets: &mut Assets,
        refusals: &mut Vec<String>,
    ) -> String {
        let html = &self.srcsets(html, assets);
        let html = &self.meta_urls(html, page);
        let attribute = &*URL_ATTRIBUTE;
        let mut out = String::with_capacity(html.len());
        let mut last = 0;
        for m in attribute.captures_iter(html) {
            let whole = m.get(0).expect("match");
            let name = m[1].to_ascii_lowercase();
            let value = m.get(3).or(m.get(4)).expect("value").as_str();
            let decoded = value.replace("&#038;", "&").replace("&amp;", "&");
            out.push_str(&html[last..whole.start()]);
            last = whole.end();
            let quote = if m.get(3).is_some() { '"' } else { '\'' };
            let Some(local) = (name != "content").then(|| self.local_path(&decoded)).flatten() else {
                out.push_str(whole.as_str());
                continue;
            };
            let (path, suffix) = split_suffix(&local);
            // A file has an extension; a `.php` URL is a WordPress endpoint (sign-in, comments), a
            // page as far as the export is concerned.
            let file = path.rsplit('/').next().unwrap_or_default().contains('.')
                && !path.ends_with(".md")
                && !path.ends_with(".php");
            let new = if file {
                if name == "action" {
                    out.push_str(whole.as_str());
                    continue;
                }
                record_asset(assets, path, suffix);
                format!("{}{path}{}", self.flags.base, version_suffix(suffix))
            } else {
                let target = path.trim_end_matches('/');
                let target = if target.is_empty() { "/" } else { target };
                let twin = path.strip_suffix(".md");
                let known = exported.contains(target)
                    || moved.contains(target)
                    || twin.is_some_and(|t| exported.contains(if t == "/index" { "/" } else { t }));
                if !known {
                    let what = if name == "action" {
                        "a form posts to"
                    } else {
                        "it links to"
                    };
                    // With --site, WordPress's own sign-in and comment endpoints stay on the hosted
                    // site, where the visitor finishes signing in or commenting.
                    if let Some(site) = &self.flags.site
                        && HOSTED
                            .iter()
                            .any(|h| target == *h || target.starts_with(&format!("{h}/")))
                    {
                        out.push_str(&format!(
                            "{}={quote}{}{}{suffix}{quote}",
                            &m[1],
                            site.trim_end_matches('/'),
                            target
                        ));
                        continue;
                    }
                    refusals.push(format!(
                        "{page}: {what} {target}, which isn't in the export. Prebuild that page (output: \"static\" prebuilds every sp/app/ page; a dynamic one needs staticParams), or link to another site's URL"
                    ));
                }
                format!("{}{target}{suffix}", self.flags.base)
            };
            out.push_str(&format!("{}={quote}{}{quote}", &m[1], new.replace('&', "&amp;")));
        }
        out.push_str(&html[last..]);
        let out = self.config(&out, page, refusals);
        let out = self.inline_styles(&out, assets);
        self.import_map(&out, assets)
    }

    /// `srcset` and `imagesrcset`: each candidate's same-site URL is a file of the export.
    fn srcsets(&self, html: &str, assets: &mut Assets) -> String {
        SRCSET
            .replace_all(html, |c: &regex::Captures| {
                let quote = if c.get(3).is_some() { '"' } else { '\'' };
                let value = c.get(3).or(c.get(4)).expect("value").as_str();
                let candidates: Vec<String> = value
                    .split(',')
                    .map(|candidate| {
                        let candidate = candidate.trim();
                        let (url, descriptor) = candidate.split_once(char::is_whitespace).unwrap_or((candidate, ""));
                        match self.local_path(&url.replace("&#038;", "&").replace("&amp;", "&")) {
                            Some(local) => {
                                let (path, suffix) = split_suffix(&local);
                                record_asset(assets, path, suffix);
                                format!("{}{path}{} {descriptor}", self.flags.base, version_suffix(suffix))
                                    .trim_end()
                                    .to_string()
                            }
                            None => candidate.to_string(),
                        }
                    })
                    .collect();
                format!("{}={quote}{}{quote}", &c[1], candidates.join(", "))
            })
            .into_owned()
    }

    /// `<link rel="canonical">` and Open Graph and Twitter URLs name where the export is served:
    /// `--site-url` and the page's path. Without `--site-url` they are left out (a relative one is
    /// not a valid canonical), so no page names the WordPress that rendered it.
    fn meta_urls(&self, html: &str, page: &str) -> String {
        let (canonical, meta, content) = (&*CANONICAL, &*META_URL, &*CONTENT);
        let html = match &self.flags.site_url {
            Some(_) => canonical
                .replace_all(
                    html,
                    format!("<link rel=\"canonical\" href=\"{}\">\n", xml(&self.absolute(page))).as_str(),
                )
                .into_owned(),
            None => canonical.replace_all(html, "").into_owned(),
        };
        meta.replace_all(&html, |c: &regex::Captures| {
            let tag = &c[0];
            let Some(m) = content.captures(tag) else {
                return tag.to_string();
            };
            let value = m
                .get(2)
                .or(m.get(3))
                .expect("value")
                .as_str()
                .replace("&#038;", "&")
                .replace("&amp;", "&");
            match (self.local_path(&value), &self.flags.site_url) {
                (Some(local), Some(site)) => tag.replacen(
                    m.get(0).expect("m").as_str(),
                    &format!("content=\"{}\"", xml(&format!("{site}{}{local}", self.flags.base))),
                    1,
                ),
                (Some(_), None) => String::new(),
                (None, _) => tag.to_string(),
            }
        })
        .into_owned()
    }

    /// `<style>` blocks (core's font faces, block styles): their `url(...)` files are exported too.
    fn inline_styles(&self, html: &str, assets: &mut Assets) -> String {
        STYLE
            .replace_all(html, |c: &regex::Captures| {
                for found in self.referenced_styles(&c[2], "/inline.css") {
                    assets.entry(found).or_default();
                }
                format!("{}{}{}", &c[1], self.relink(&c[2]), &c[3])
            })
            .into_owned()
    }

    /// The Interactivity API's import map: its module URLs are files of the export.
    fn import_map(&self, html: &str, assets: &mut Assets) -> String {
        IMPORT_MAP
            .replace_all(html, |c: &regex::Captures| {
                let Ok(mut map) = serde_json::from_str::<Value>(&c[2]) else {
                    return c[0].to_string();
                };
                if let Some(imports) = map["imports"].as_object_mut() {
                    for value in imports.values_mut() {
                        if let Some(local) = value.as_str().and_then(|u| self.local_path(u)) {
                            let (path, suffix) = split_suffix(&local);
                            record_asset(assets, path, suffix);
                            *value = Value::String(format!("{}{path}{}", self.flags.base, version_suffix(suffix)));
                        }
                    }
                }
                format!("{}{}{}", &c[1], script_json(&map), &c[3])
            })
            .into_owned()
    }

    /// Client component configuration (`wp-script-module-data-*`): action endpoints stay on the
    /// hosted site with `--site` (public actions only); without one, a component that calls an action
    /// is refused.
    fn config(&self, html: &str, page: &str, refusals: &mut Vec<String>) -> String {
        MODULE_DATA.replace_all(html, |c: &regex::Captures| {
            if !c[1].contains(r#"type="application/json""#) {
                return c[0].to_string();
            }
            // The typed client's data (`Islands::clientData`) names the site's REST API. Without
            // --site a static site has none (an action call is refused below), so it goes.
            if &c[2] == "@spacepress/client" && self.flags.site.is_none() {
                return String::new();
            }
            let Ok(mut data) = serde_json::from_str::<Value>(&c[3]) else { return c[0].to_string() };
            let mut changed = false;
            // core/router's server URL: the page's address where the export is served.
            if let Some(url) = data.pointer("/state/core~1router/url").and_then(Value::as_str)
                && let Some(local) = self.local_path(url)
            {
                let (path, suffix) = split_suffix(&local);
                let served = format!("{}{}", self.flags.site_url.as_deref().unwrap_or(""), self.flags.base);
                data["state"]["core/router"]["url"] = Value::String(format!("{served}{path}{suffix}"));
                changed = true;
            }
            // With --site, the typed client's REST root is the hosted site, where the page's actions run.
            if &c[2] == "@spacepress/client"
                && let (Some(site), Some(root)) = (&self.flags.site, data.get("root").and_then(Value::as_str))
                && let Some(local) = self.local_path(root)
            {
                data["root"] = Value::String(format!("{}{local}", site.trim_end_matches('/')));
                changed = true;
            }
            if let Some(namespaces) = data.get_mut("config").and_then(Value::as_object_mut) {
                for (namespace, config) in namespaces.iter_mut() {
                    let Some(actions) = config["actions"].as_object_mut() else { continue };
                    for (action, url) in actions.iter_mut() {
                        match &self.flags.site {
                            None => refusals.push(format!(
                                "{page}: the client component {namespace} calls the action `{action}`, and a static site has no server to run it. Export with --site <url> to call it on the hosted site, or remove the call"
                            )),
                            Some(_) if self.public_actions.get(action) != Some(&true) => refusals.push(format!(
                                "{page}: the client component {namespace} calls the action `{action}`, which isn't `access: \"anyone\"`; a visitor of the static site isn't signed in to the hosted one. Make the action public, or remove the call"
                            )),
                            Some(site) => {
                                // Absolute on the hosted site, which answers it cross-origin (the API's CORS).
                                if let Some(local) = url.as_str().and_then(|u| self.local_path(u)) {
                                    *url = Value::String(format!("{}{}", site.trim_end_matches('/'), local));
                                    changed = true;
                                }
                            }
                        }
                    }
                }
            }
            if changed {
                format!("{}\n{}\n{}", &c[1], script_json(&data), &c[4])
            } else {
                c[0].to_string()
            }
        })
        .into_owned()
    }

    fn rewrite_markdown(&self, md: &str) -> String {
        md.replace(
            &format!("{}{}", self.origin, self.home),
            &self
                .flags
                .site_url
                .clone()
                .map(|u| format!("{u}{}", self.flags.base))
                .unwrap_or_else(|| self.flags.base.clone()),
        )
    }

    /// URLs a stylesheet references with `url(...)` or `@import`.
    fn referenced_styles(&self, text: &str, from: &str) -> Vec<String> {
        let dir = from.rsplit_once('/').map(|(d, _)| d).unwrap_or("");
        let mut out = Vec::new();
        for c in CSS_REFERENCE.captures_iter(text) {
            let raw = c.get(1).or(c.get(2)).expect("reference").as_str();
            if raw.starts_with("data:") || raw.starts_with('#') || raw.starts_with("@wordpress/") || raw.contains("${")
            {
                continue;
            }
            let local = if raw.starts_with("http") || raw.starts_with('/') {
                match self.local_path(raw) {
                    Some(p) => p,
                    None => continue,
                }
            } else if raw.starts_with("./") || raw.starts_with("../") || !raw.contains(':') {
                normalize(&format!("{dir}/{raw}"))
            } else {
                continue;
            };
            let (path, _) = split_suffix(&local);
            out.push(path.to_string());
        }
        out
    }

    /// Discover actual module specifiers and rewrite the ones anchored to the rendering site.
    fn rewrite_module(&self, text: &str, from: &str) -> Result<(String, AssetRequests), String> {
        use oxc_ast::{AstKind, ast::Expression};
        use oxc_span::{GetSpan, SourceType};

        let allocator = oxc_allocator::Allocator::default();
        let parsed = oxc_parser::Parser::new(&allocator, text, SourceType::unambiguous()).parse();
        if let Some(error) = parsed.diagnostics.first() {
            return Err(format!("could not read module dependencies in {from}: {error}"));
        }
        let semantic = oxc_semantic::SemanticBuilder::new()
            .with_build_nodes(true)
            .build(&parsed.program);
        let location = url::Url::parse(&format!("{}{}{from}", self.origin, self.home))
            .map_err(|error| format!("invalid module URL {from}: {error}"))?;
        let mut references = AssetRequests::new();
        let mut edits = Vec::new();
        for node in semantic.semantic.nodes().iter() {
            let literal = match node.kind() {
                AstKind::ImportDeclaration(decl) => Some(&decl.source),
                AstKind::ExportFromDeclaration(decl) => Some(&decl.source),
                AstKind::ExportAllDeclaration(decl) => Some(&decl.source),
                AstKind::ImportExpression(expr) => match &expr.source {
                    Expression::StringLiteral(literal) => Some(&**literal),
                    _ => None,
                },
                _ => None,
            };
            let reference = if let Some(literal) = literal {
                literal.value.as_str().map(|value| (value, literal.span()))
            } else if let AstKind::ImportExpression(expr) = node.kind()
                && let Expression::TemplateLiteral(template) = &expr.source
                && template.expressions.is_empty()
            {
                template.quasis[0]
                    .value
                    .cooked
                    .and_then(|value| value.as_str())
                    .map(|value| (value, template.span()))
            } else {
                None
            };
            if let Some((value, span)) = reference {
                self.module_reference(&location, value, span, &mut references, &mut edits);
            }
        }

        // Splice only the parsed literals; keep comments and the rest of the published module intact.
        edits.sort_unstable_by_key(|(span, _)| std::cmp::Reverse(span.start));
        let mut rewritten = text.to_owned();
        for (span, value) in edits {
            rewritten.replace_range(span.start as usize..span.end as usize, &value);
        }
        Ok((self.relink(&rewritten), references))
    }

    fn module_reference(
        &self,
        location: &url::Url,
        specifier: &str,
        span: oxc_span::Span,
        references: &mut AssetRequests,
        edits: &mut Vec<(oxc_span::Span, String)>,
    ) {
        let relative = specifier.starts_with("./") || specifier.starts_with("../");
        if !relative && !specifier.starts_with('/') && url::Url::parse(specifier).is_err() {
            return; // Bare specifiers are handled by the import map.
        }
        let Some(local) = location
            .join(specifier)
            .ok()
            .and_then(|url| self.local_path(url.as_str()))
        else {
            return;
        };
        let (path, suffix) = split_suffix(&local);
        let query = suffix.split('#').next().unwrap_or_default();
        references.insert((path.to_string(), query.to_string()));
        if !relative {
            let output = format!("{}{local}", self.flags.base);
            if output != specifier {
                edits.push((span, Value::String(output).to_string()));
            }
        }
    }

    /// Absolute same-site URLs inside a file become root-relative under `--base`.
    fn relink(&self, text: &str) -> String {
        text.replace(
            &format!("{}{}/", self.origin, self.home),
            &format!("{}/", self.flags.base),
        )
    }

    fn sitemap(&self, pages: &[Page]) -> String {
        let mut out = String::from(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n",
        );
        for page in pages {
            if robots_noindex(&page.html) {
                continue;
            }
            out.push_str(&format!(
                "  <url><loc>{}</loc></url>\n",
                xml(&self.absolute(if page.path == "/" { "/" } else { &page.path }))
            ));
        }
        out.push_str("</urlset>\n");
        out
    }

    fn llms(&self, pages: &[Page]) -> String {
        let mut out = format!(
            "# {}\n\nEvery page has a Markdown version at its URL plus `.md`.\n\n## Pages\n\n",
            self.name
        );
        for page in pages
            .iter()
            .filter(|p| p.markdown.is_some() && !robots_noindex(&p.html))
        {
            let title = page
                .markdown
                .as_deref()
                .and_then(|m| m.lines().find_map(|l| l.strip_prefix("# ")))
                .unwrap_or(&page.path);
            let md = if page.path == "/" {
                "/index.md".to_string()
            } else {
                format!("{}.md", page.path)
            };
            out.push_str(&format!(
                "- [{}]({})\n",
                title.replace(['[', ']'], ""),
                self.absolute(&md)
            ));
        }
        out
    }
}

fn robots_noindex(html: &str) -> bool {
    NOINDEX.is_match(html)
}

fn xml(s: &str) -> String {
    s.replace('&', "&amp;").replace('<', "&lt;").replace('>', "&gt;")
}

/// `/a/b?x#y` → (`/a/b`, `?x#y`).
fn split_suffix(path: &str) -> (&str, &str) {
    match path.find(['?', '#']) {
        Some(i) => (&path[..i], &path[i..]),
        None => (path, ""),
    }
}

/// Preserve WordPress versions and the full suffix of versioned public modules.
fn version_suffix(suffix: &str) -> &str {
    if (suffix.starts_with("?ver=") && !suffix.contains('&')) || public_module_query(suffix).is_some() {
        suffix
    } else {
        ""
    }
}

fn public_module_query(suffix: &str) -> Option<&str> {
    let query = suffix.split('#').next()?;
    query
        .strip_prefix('?')?
        .split('&')
        .any(|part| part.starts_with("ver=") || part.starts_with("spv="))
        .then_some(query)
}

fn record_asset(assets: &mut Assets, path: &str, suffix: &str) {
    let versioned = assets.entry(path.to_string()).or_default();
    if let Some(query) = public_module_query(suffix) {
        *versioned = query.to_string();
    }
}

/// `/a/./b/../c` → `/a/c`.
fn normalize(path: &str) -> String {
    let mut parts: Vec<&str> = Vec::new();
    for part in path.split('/') {
        match part {
            "" | "." => {}
            ".." => {
                parts.pop();
            }
            p => parts.push(p),
        }
    }
    format!("/{}", parts.join("/"))
}

/// The file a page path is written to: `/` → `index.html`, `/a/b` → `a/b.html`. Each segment is the
/// percent-decoded name a static server maps the URL to. A segment that would leave the export (`..`,
/// `.`, empty, a decoded `/`, `\` or NUL, a drive or root) is refused: paths come from the site.
fn file_of(path: &str, extension: &str) -> Result<PathBuf, String> {
    if path == "/" {
        return Ok(PathBuf::from(format!("index.{extension}")));
    }
    let mut file = PathBuf::new();
    let segments = segments_of(path)?;
    for (i, s) in segments.iter().enumerate() {
        if i + 1 == segments.len() {
            file.push(format!("{s}.{extension}"));
        } else {
            file.push(s);
        }
    }
    Ok(file)
}

/// The file an asset path is written to: its decoded segments, refused as for a page.
fn asset_file(path: &str) -> Result<PathBuf, String> {
    Ok(segments_of(path)?.iter().collect())
}

fn segments_of(path: &str) -> Result<Vec<String>, String> {
    let raw: Vec<&str> = path.strip_prefix('/').unwrap_or(path).split('/').collect();
    let mut out = Vec::new();
    for s in raw {
        let decoded = super::links::percent_decode(s);
        if decoded.is_empty()
            || decoded == "."
            || decoded == ".."
            || decoded.contains(['/', '\\', '\0'])
            || (decoded.len() >= 2 && decoded.as_bytes()[1] == b':')
        {
            return Err(format!(
                "{path} isn't a path the export can write: a segment is `{decoded}`"
            ));
        }
        out.push(decoded);
    }
    Ok(out)
}

fn write(path: &Path, bytes: &[u8]) -> Result<(), String> {
    if let Some(dir) = path.parent() {
        std::fs::create_dir_all(dir).map_err(|e| format!("{}: {e}", dir.display()))?;
    }
    let mut file = std::fs::File::create(path).map_err(|e| format!("{}: {e}", path.display()))?;
    file.write_all(bytes).map_err(|e| format!("{}: {e}", path.display()))
}

/// Text files below `dir` that still contain `origin`, by path below `root`.
fn leftover(root: &Path, dir: &Path, origin: &str, out: &mut Vec<String>) {
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    for entry in entries.flatten() {
        let path = entry.path();
        if path.is_dir() {
            leftover(root, &path, origin, out);
        } else if matches!(
            path.extension().and_then(|e| e.to_str()),
            Some("html" | "md" | "css" | "js" | "txt" | "xml" | "json")
        ) && std::fs::read_to_string(&path).is_ok_and(|t| t.contains(origin))
        {
            out.push(path.strip_prefix(root).unwrap_or(&path).display().to_string());
        }
    }
}

/// The marker an export folder carries: only a folder that has it is ever replaced.
const MARKER: &str = ".spacepress-export";

/// Where the export goes, refused when replacing it could lose anything else: it may not be the
/// project or hold it, sit inside the project outside `sp/dist/`, or be a folder that isn't an
/// earlier export.
fn output_dir(project: &Path, out: Option<&Path>) -> Result<PathBuf, String> {
    let out = match out {
        None => project.join(OUT),
        Some(out) => {
            let absolute = if out.is_absolute() {
                out.to_path_buf()
            } else {
                std::env::current_dir().map_err(|e| e.to_string())?.join(out)
            };
            let name = absolute
                .file_name()
                .filter(|n| *n != "." && *n != "..")
                .ok_or("--out names a folder, like dist/static")?
                .to_owned();
            let parent = normalize_path(absolute.parent().ok_or("--out needs a parent folder")?);
            let parent = parent.canonicalize().unwrap_or(parent);
            parent.join(name)
        }
    };
    let dist = project.join(crate::layout::DIST);
    if project.starts_with(&out) {
        return Err(format!(
            "--out {} holds the project; choose a folder outside it, or leave --out out for {OUT}",
            out.display()
        ));
    }
    if out.starts_with(project) && !out.starts_with(&dist) {
        return Err(format!(
            "--out {} is inside the project; put the export below {} or outside the project",
            out.display(),
            crate::layout::DIST
        ));
    }
    if out == dist || out == project.join(crate::layout::WP_CONTENT) {
        return Err(format!(
            "--out {} is where `spacepress build` writes; choose a folder of its own, like {OUT}",
            out.display()
        ));
    }
    if out.exists() {
        let empty = std::fs::read_dir(&out).map(|mut d| d.next().is_none()).unwrap_or(false);
        if !out.is_dir() || (!empty && !out.join(MARKER).is_file()) {
            return Err(format!(
                "--out {} already holds files that aren't an export; spacepress export replaces only its own output, so choose an empty or new folder",
                out.display()
            ));
        }
    }
    Ok(out)
}

/// `a/./b/../c` without touching the disk (the folder may not exist yet).
fn normalize_path(path: &Path) -> PathBuf {
    let mut out = PathBuf::new();
    for part in path.components() {
        match part {
            std::path::Component::CurDir => {}
            std::path::Component::ParentDir => {
                out.pop();
            }
            other => out.push(other),
        }
    }
    out
}

/// JSON safe inside a `<script>` element, as WordPress writes it (`JSON_HEX_TAG | JSON_HEX_AMP`):
/// editor text in state can never close the element.
fn script_json(value: &Value) -> String {
    value
        .to_string()
        .replace('<', "\\u003C")
        .replace('>', "\\u003E")
        .replace('&', "\\u0026")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn page_paths_map_to_the_files_static_hosts_serve() {
        assert_eq!(file_of("/", "html").unwrap(), PathBuf::from("index.html"));
        assert_eq!(
            file_of("/docs/intro", "html").unwrap(),
            PathBuf::from("docs/intro.html")
        );
        assert_eq!(
            file_of("/tags/lab%20notes", "md").unwrap(),
            PathBuf::from("tags/lab notes.md")
        );
        // A path from the site that would leave the export is refused, however it is spelled.
        for bad in [
            "/a/../../x",
            "/a/%2e%2e/x",
            "/a/%2Fetc%2Fpasswd",
            "/a/%5C..%5Cx",
            "/a//b",
            "/a/%00",
            "/C:%5Cx",
        ] {
            assert!(file_of(bad, "html").is_err(), "{bad}");
            assert!(asset_file(bad).is_err(), "{bad}");
        }
        assert_eq!(
            asset_file("/wp-content/a b.css").unwrap(),
            PathBuf::from("wp-content/a b.css")
        );
        // A segment decodes as a URL path does: `&`, `=` and `+` are themselves.
        assert_eq!(
            asset_file("/wp-content/R%26D/a&b=c+d%2Be.css").unwrap(),
            PathBuf::from("wp-content/R&D/a&b=c+d+e.css")
        );
        assert_eq!(normalize("/a/b/../c/./d.woff2"), "/a/c/d.woff2");
        assert_eq!(split_suffix("/x.js?ver=1#y"), ("/x.js", "?ver=1#y"));
        assert_eq!(version_suffix("?ver=6a43"), "?ver=6a43");
        assert_eq!(version_suffix("?a=1"), "");
        assert_eq!(
            version_suffix("?mode=one&mode=two&spv=6a43#ready"),
            "?mode=one&mode=two&spv=6a43#ready"
        );
        assert_eq!(version_suffix("?mode=one#spv=6a43"), "");
    }

    #[test]
    fn the_export_fetches_the_versioned_public_module() {
        let flags = parse(&["--base".into(), "/docs".into()]).unwrap();
        let listing = serde_json::json!({});
        let export = Export::new(
            "https://render.example.test",
            &flags,
            &crate::schema::Schema::default(),
            &listing,
        )
        .unwrap();
        let html = concat!(
            r#"<script type="module" src="https://render.example.test/module.mjs?mode=one&#038;spv=6a43#ready"></script>"#,
            r#"<link rel="modulepreload" href="https://render.example.test/module.mjs">"#,
        );
        let mut assets = Assets::new();
        let mut refusals = Vec::new();
        let output = export.rewrite(
            html,
            "/",
            &BTreeSet::new(),
            &BTreeSet::new(),
            &mut assets,
            &mut refusals,
        );
        assert!(
            output.contains(r#"src="/docs/module.mjs?mode=one&amp;spv=6a43#ready""#),
            "{output}"
        );
        assert!(refusals.is_empty());
        assert_eq!(assets["/module.mjs"], "?mode=one&spv=6a43");
        assert_eq!(
            export
                .rewrite_module(r#"import { name } from "./other.mjs";"#, "/scripts/module.mjs")
                .unwrap()
                .1,
            AssetRequests::from([("/scripts/other.mjs".to_string(), String::new())])
        );
    }

    #[test]
    fn static_export_copies_and_rewrites_only_real_module_dependencies() {
        for conflicting in [false, true] {
            check_module_dependency_export(conflicting);
        }
    }

    fn check_module_dependency_export(conflicting: bool) {
        use std::net::TcpListener;

        let listener = TcpListener::bind(("127.0.0.1", 0)).unwrap();
        listener.set_nonblocking(true).unwrap();
        let origin = format!("http://{}", listener.local_addr().unwrap());
        let source = format!(
            r#"
            // import "/site/comment-only.mjs";
            const example = 'from "/site/string-only.mjs"';
            import "/site/scripts/root.mjs";
            export {{ default as config }} from "./config.mjs?build=blue#ready";
            export {{ default as alternate }} from "./config.mjs?build=green";
            const chunk = () => import(`./chunk.mjs`);
            const logo = () => fetch("{origin}/site/logo.svg");
            "#
        );
        let serve = source.clone();
        let (stop, stopped) = std::sync::mpsc::channel();
        let worker = std::thread::spawn(move || {
            let mut received = BTreeSet::new();
            loop {
                let (mut stream, _) = match listener.accept() {
                    Ok(client) => client,
                    Err(error) if error.kind() == std::io::ErrorKind::WouldBlock => {
                        if stopped.recv_timeout(Duration::from_millis(5)).is_ok() {
                            break;
                        }
                        continue;
                    }
                    Err(error) => panic!("local asset fixture: {error}"),
                };
                stream.set_nonblocking(false).unwrap();

                stream.set_read_timeout(Some(Duration::from_secs(1))).unwrap();
                let mut reader = std::io::BufReader::new(&stream);
                let mut first = String::new();
                reader.read_line(&mut first).unwrap();
                let target = first.split_whitespace().nth(1).unwrap().to_string();
                let mut line = String::new();
                loop {
                    line.clear();
                    reader.read_line(&mut line).unwrap();
                    if line == "\r\n" || line.is_empty() {
                        break;
                    }
                }
                drop(reader);
                let (status, body) = match target.as_str() {
                    "/site/" => (
                        "200 OK",
                        r#"<script src="/site/scripts/main.mjs" type="module"></script>"#,
                    ),
                    "/site/scripts/main.mjs" => ("200 OK", serve.as_str()),
                    "/site/scripts/config.mjs?build=blue" => ("200 OK", "export default 1;"),
                    "/site/scripts/config.mjs?build=green" if conflicting => ("200 OK", "export default 2;"),
                    "/site/scripts/config.mjs?build=green" => ("200 OK", "export default 1;"),
                    "/site/scripts/root.mjs" | "/site/scripts/chunk.mjs" => ("200 OK", "export {};"),
                    "/__sp-not-found" => ("404 Not Found", "<p>Missing</p>"),
                    _ => ("404 Not Found", ""),
                };
                write!(
                    stream,
                    "HTTP/1.1 {status}\r\nX-SpacePress-Prerendered: 1\r\nConnection: close\r\nContent-Length: {}\r\n\r\n{body}",
                    body.len()
                )
                .unwrap();
                assert!(received.insert(target));
            }
            received
        });
        let flags = parse(&["--base".into(), "/docs".into()]).unwrap();
        let listing = serde_json::json!({"home": format!("{origin}/site"), "urls": [{"path": "/"}]});
        let export = Export::new(&origin, &flags, &crate::schema::Schema::default(), &listing).unwrap();
        let temp = tempfile::tempdir().unwrap();
        let out = temp.path().join("out");
        let result = export.write(&out);
        stop.send(()).unwrap();
        let received = worker.join().unwrap();
        assert!(received.contains("/site/scripts/config.mjs?build=blue"));
        assert!(received.contains("/site/scripts/config.mjs?build=green"));
        if conflicting {
            assert!(
                result
                    .err()
                    .unwrap()
                    .contains("different asset queries return different content")
            );
            assert!(!out.exists());
            return;
        }
        result.unwrap();

        let main = std::fs::read_to_string(out.join("scripts/main.mjs")).unwrap();
        assert!(main.contains(r#"import "/docs/scripts/root.mjs";"#), "{main}");
        assert!(main.contains(r#"from "./config.mjs?build=blue#ready";"#), "{main}");
        assert!(main.contains(r#"fetch("/docs/logo.svg")"#), "{main}");
        assert!(main.contains(r#"// import "/site/comment-only.mjs";"#), "{main}");
        assert_eq!(
            std::fs::read_to_string(out.join("scripts/config.mjs")).unwrap(),
            "export default 1;"
        );
        assert!(out.join("scripts/root.mjs").is_file());
        assert!(out.join("scripts/chunk.mjs").is_file());
    }
}
