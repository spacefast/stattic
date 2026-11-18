//! `typedpress doctor --links`: every link and `#anchor` a site's own pages make, followed.
//!
//! A TypedPress site's pages come from content (synced Markdown, edited in wp-admin), TSX and WordPress
//! itself, so a broken link can come from any of them and only shows on the served site. The check
//! reads the site as a visitor does:
//!
//! - It starts at the home page, the sitemap's pages and the pages `/llms.txt` lists. Each of those
//!   is itself checked: a sitemap entry that is gone is reported against the sitemap.
//! - It reads every HTML page on the site (`Content-Type: text/html`), breadth first, following
//!   same-site redirects to the page they end at, and judges every link, image, stylesheet, script
//!   and media source each page makes.
//! - A link is broken when it doesn't end, through at most five same-site redirects, in a 2xx answer
//!   or a redirect to another site; a `#fragment` is broken when the page it ends at has no element
//!   with that id (or `<a name>`).
//!
//! Links to other sites and WordPress's own paths (`/wp-admin`, `/wp-json`, feeds) are never
//! fetched. Text in comments, `<script>`, `<style>`, `<template>`, `<noscript>`, `<textarea>`,
//! `<pre>` and `<code>` isn't markup a visitor follows and is left alone.

use std::collections::{BTreeMap, BTreeSet, VecDeque};
use std::sync::LazyLock;
use std::time::Duration;
use url::Url;

/// Pages read per run, and distinct URLs judged: a larger site is reported as truncated.
const LIMIT: usize = 5000;
const TARGETS: usize = 50_000;
/// Fetches in flight at once.
const WORKERS: usize = 8;

/// One broken link: the page it is on, the URL as written there, and what is wrong.
#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord)]
pub struct Broken {
    pub on: String,
    pub href: String,
    pub problem: String,
}

pub struct Report {
    pub pages: usize,
    pub broken: Vec<Broken>,
    pub truncated: bool,
    /// Why nothing could be checked (the home page isn't a page), when that is so.
    pub failed: Option<String>,
}

/// What a fetch answered.
#[derive(Debug, Clone, Default)]
pub struct Answer {
    pub status: u16,
    /// `Location`, when the answer is a redirect.
    pub location: Option<String>,
    /// The body, for an HTML answer to a GET (and for the sitemap and llms.txt).
    pub body: Option<String>,
    /// Whether the answer is HTML.
    pub html: bool,
}

/// How a URL is read: over HTTP for a site, and a stub in tests.
pub trait Fetch: Sync {
    /// `body` asks for the body; without it a fetch may use HEAD.
    fn get(&self, url: &Url, body: bool) -> Result<Answer, String>;
}

/// The site over HTTP, one connection pool shared by every worker. Redirects are followed one hop
/// at a time by the caller, so each hop stays on the site.
pub struct Http(ureq::Agent);

impl Http {
    pub fn new() -> Self {
        Http(
            ureq::Agent::config_builder()
                .timeout_global(Some(Duration::from_secs(30)))
                .max_redirects(0)
                .http_status_as_error(false)
                .build()
                .into(),
        )
    }

    fn request(&self, url: &Url, head: bool) -> Result<Answer, String> {
        let mut response = if head {
            self.0.head(url.as_str()).call()
        } else {
            self.0.get(url.as_str()).call()
        }
        .map_err(|e| e.to_string())?;
        let status = response.status().as_u16();
        let header = |name: &str| {
            response
                .headers()
                .get(name)
                .and_then(|v| v.to_str().ok())
                .map(str::to_string)
        };
        let location = header("location");
        let kind = header("content-type").unwrap_or_default().to_ascii_lowercase();
        let html = kind.contains("text/html") || kind.contains("application/xhtml");
        let text = html || kind.contains("xml") || kind.contains("text/plain") || kind.contains("text/markdown");
        // Read every body to its end, so the connection goes back to the pool.
        let reader = response
            .body_mut()
            .with_config()
            .limit(16 * 1024 * 1024)
            .lossy_utf8(true);
        let body = if !head && text && (200..300).contains(&status) {
            Some(reader.read_to_string().map_err(|e| e.to_string())?)
        } else {
            if !head {
                let _ = std::io::copy(&mut reader.reader(), &mut std::io::sink());
            }
            None
        };
        Ok(Answer {
            status,
            location,
            body,
            html,
        })
    }
}

impl Fetch for Http {
    fn get(&self, url: &Url, body: bool) -> Result<Answer, String> {
        if body {
            return self.request(url, false);
        }
        // An asset needs its status only. A server that refuses HEAD gets a GET.
        match self.request(url, true) {
            Ok(answer) if !matches!(answer.status, 405 | 501) => Ok(answer),
            _ => self.request(url, false),
        }
    }
}

static TAG: LazyLock<regex::Regex> = LazyLock::new(|| {
    // A start tag, its attributes read with quoted values whole (a `>` inside one doesn't end it).
    regex::Regex::new(r#"(?is)<([a-z][a-z0-9:-]*)((?:[^>"']|"[^"]*"|'[^']*')*)>"#).expect("tag pattern")
});
static ATTR: LazyLock<regex::Regex> = LazyLock::new(|| {
    regex::Regex::new(r#"(?is)([^\s"'=<>/`]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?"#)
        .expect("attribute pattern")
});
static QUIET: LazyLock<Vec<regex::Regex>> = LazyLock::new(|| {
    let mut out = vec![regex::Regex::new(r"(?s)<!--.*?-->").expect("comment pattern")];
    // Each element's name must end where the name does (`<code>`, not `<code-tabs>`).
    for element in ["script", "style", "template", "noscript", "textarea", "code", "pre"] {
        out.push(
            regex::Regex::new(&format!(r"(?is)<{element}(?:[\s/][^>]*)?>.*?</{element}\s*>")).expect("quiet pattern"),
        );
    }
    out
});
static LOC: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"<loc>\s*([^<\s]+)\s*</loc>").expect("loc pattern"));
static MD_LINK: LazyLock<regex::Regex> =
    LazyLock::new(|| regex::Regex::new(r"\]\((https?://[^)\s]+)\)").expect("llms link pattern"));

/// A start tag: its name and its attributes, names lowercased, values decoded.
struct Tag {
    name: String,
    attrs: Vec<(String, String)>,
}

impl Tag {
    fn get(&self, name: &str) -> Option<&str> {
        self.attrs.iter().find(|(n, _)| n == name).map(|(_, v)| v.as_str())
    }
}

/// The start tags of `html`, in order, outside comments and the elements whose text isn't markup.
/// `keep` keeps the start tags of those elements (a `<script src>` is a link; a `<pre id>` an
/// anchor) while dropping what they hold.
fn tags(html: &str) -> Vec<Tag> {
    let mut text = html.to_string();
    for (i, quiet) in QUIET.iter().enumerate() {
        text = quiet
            .replace_all(&text, |c: &regex::Captures| {
                if i == 0 {
                    return String::new();
                }
                // Keep the element's own start tag: its `src` or `id` counts.
                let whole = &c[0];
                let end = whole.find('>').map_or(whole.len(), |e| e + 1);
                whole[..end].to_string()
            })
            .into_owned();
    }
    TAG.captures_iter(&text)
        .map(|t| Tag {
            name: t[1].to_ascii_lowercase(),
            attrs: ATTR
                .captures_iter(&t[2])
                .map(|a| {
                    let value = a
                        .get(2)
                        .or(a.get(3))
                        .or(a.get(4))
                        .map(|m| decode(m.as_str()))
                        .unwrap_or_default();
                    (a[1].to_ascii_lowercase(), value)
                })
                .collect(),
        })
        .collect()
}

/// The links a page makes: `(attribute value, read as a page)`. `<a href>` and `<area href>` are
/// pages; `src`, `poster`, `data`, `srcset` candidates and the `<link>`s a reader or an agent uses
/// are assets, checked but not read.
fn links(tags: &[Tag]) -> Vec<(String, bool)> {
    let mut out = Vec::new();
    for tag in tags {
        match tag.name.as_str() {
            "a" | "area" => out.extend(tag.get("href").map(|h| (h.to_string(), true))),
            "link" => {
                // A stylesheet, an icon, a preload, an alternate form (a Markdown twin, a feed)
                // or the canonical URL. WordPress's API discovery, shortlink and pingback links
                // are for its own clients.
                let rel = tag.get("rel").unwrap_or_default().to_ascii_lowercase();
                if rel.split_whitespace().any(|r| {
                    matches!(
                        r,
                        "stylesheet"
                            | "icon"
                            | "apple-touch-icon"
                            | "preload"
                            | "modulepreload"
                            | "manifest"
                            | "alternate"
                            | "canonical"
                    )
                }) {
                    out.extend(tag.get("href").map(|h| (h.to_string(), false)));
                }
            }
            "img" | "script" | "source" | "iframe" | "video" | "audio" | "track" | "embed" | "input" => {
                for attr in ["src", "poster"] {
                    out.extend(tag.get(attr).map(|v| (v.to_string(), false)));
                }
                if let Some(set) = tag.get("srcset") {
                    // Candidates are `url [descriptor]`, separated by commas that follow white
                    // space or end a descriptor; a comma inside a URL stays.
                    for candidate in set.split(", ").flat_map(|c| c.split(",\n")) {
                        if let Some(url) = candidate.split_whitespace().next() {
                            out.push((url.to_string(), false));
                        }
                    }
                }
            }
            "object" => out.extend(tag.get("data").map(|v| (v.to_string(), false))),
            _ => {}
        }
    }
    out
}

/// The ids a page has for `#fragment` links: every element's `id`, and `name` on `<a>`.
fn anchors(tags: &[Tag]) -> BTreeSet<String> {
    let mut out = BTreeSet::new();
    for tag in tags {
        if let Some(id) = tag.get("id") {
            out.insert(id.to_string());
        }
        if tag.name == "a"
            && let Some(name) = tag.get("name")
        {
            out.insert(name.to_string());
        }
    }
    out
}

/// The character references attribute values carry in practice.
fn decode(value: &str) -> String {
    value
        .replace("&amp;", "&")
        .replace("&#038;", "&")
        .replace("&#38;", "&")
        .replace("&quot;", "\"")
        .replace("&#039;", "'")
        .replace("&#39;", "'")
        .replace("&lt;", "<")
        .replace("&gt;", ">")
}

fn same_site(site: &Url, url: &Url) -> bool {
    let base = site.path().trim_end_matches('/');
    url.scheme() == site.scheme()
        && url.host_str() == site.host_str()
        && url.port_or_known_default() == site.port_or_known_default()
        && (base.is_empty() || url.path() == base || url.path().starts_with(&format!("{base}/")))
}

/// What WordPress serves for itself, not the site's pages: never followed.
fn wordpress_own(site: &Url, url: &Url) -> bool {
    let path = &url.path()[site.path().trim_end_matches('/').len()..];
    [
        "/wp-admin",
        "/wp-login.php",
        "/wp-json",
        "/xmlrpc.php",
        "/wp-cron.php",
        "/feed",
        "/comments/feed",
        "/__tp",
        "/__spacefast",
    ]
    .iter()
    .any(|p| path == *p || path.starts_with(&format!("{p}/")))
        || url
            .query()
            .is_some_and(|q| q.contains("replytocom=") || q.contains("rest_route="))
}

/// Where a URL ends: through at most five same-site redirects, keyed by URL.
struct Crawl<'a> {
    site: &'a Url,
    fetch: &'a dyn Fetch,
    /// Every answer, by URL without fragment.
    answers: BTreeMap<String, Answer>,
}

/// Where a URL ended up.
enum End {
    /// A same-site answer that isn't a redirect: the final URL and its answer.
    Here(Url, Answer),
    /// A redirect to another site: fine.
    Away,
    Broken(String),
}

impl Crawl<'_> {
    /// Fetches every URL not yet answered, `WORKERS` at a time.
    fn prefetch(&mut self, urls: &[(Url, bool)]) {
        let todo: Vec<&(Url, bool)> = urls
            .iter()
            .filter(|(u, _)| !self.answers.contains_key(u.as_str()))
            .collect();
        if todo.is_empty() {
            return;
        }
        let next = std::sync::atomic::AtomicUsize::new(0);
        let done = std::sync::Mutex::new(Vec::new());
        std::thread::scope(|s| {
            for _ in 0..WORKERS.min(todo.len()) {
                s.spawn(|| {
                    loop {
                        let i = next.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                        let Some((url, body)) = todo.get(i) else { break };
                        let answer = self.fetch.get(url, *body).unwrap_or_default();
                        done.lock().expect("results").push((url.to_string(), answer));
                    }
                });
            }
        });
        for (key, answer) in done.into_inner().expect("results") {
            self.answers.entry(key).or_insert(answer);
        }
    }

    fn answer(&mut self, url: &Url, body: bool) -> Answer {
        if let Some(answer) = self.answers.get(url.as_str()) {
            return answer.clone();
        }
        let answer = self.fetch.get(url, body).unwrap_or_default();
        self.answers.insert(url.to_string(), answer.clone());
        answer
    }

    /// Follows `url`'s same-site redirects (reading a page's body when `body`).
    fn end(&mut self, url: &Url, body: bool) -> End {
        let mut current = url.clone();
        let mut seen = BTreeSet::new();
        loop {
            if !seen.insert(current.to_string()) || seen.len() > 6 {
                return End::Broken("redirects in a loop or more than five times".into());
            }
            let answer = self.answer(&current, body);
            match answer.status {
                0 => return End::Broken("couldn't be read".into()),
                300..=399 => {
                    let Some(next) = answer.location.as_deref().and_then(|l| current.join(l).ok()) else {
                        return End::Broken(format!("answers {} without a Location", answer.status));
                    };
                    if !same_site(self.site, &next) {
                        return End::Away;
                    }
                    current = next;
                    current.set_fragment(None);
                }
                200..=299 => return End::Here(current, answer),
                status => return End::Broken(format!("answers {status}")),
            }
        }
    }
}

/// Checks the site at `site`.
pub fn check(site: &Url, fetch: &dyn Fetch) -> Report {
    let mut crawl = Crawl {
        site,
        fetch,
        answers: BTreeMap::new(),
    };
    let mut broken = BTreeSet::new();
    // (page it is on, the href as written, the absolute URL without fragment, the fragment, a page to read)
    let mut found: Vec<(String, String, Url, Option<String>, bool)> = Vec::new();

    // The home page must be a page, or nothing is checked.
    let home = match crawl.end(site, true) {
        End::Here(url, answer) if answer.html => url,
        End::Here(_, _) => return failed("the home page isn't HTML"),
        End::Away => return failed("the home page redirects to another site; pass that site's URL"),
        End::Broken(problem) => return failed(&format!("the home page {problem}")),
    };
    // The sitemap's pages and llms.txt's, each judged as a link of the file that lists it.
    for (file, pattern) in [
        ("wp-sitemap.xml", &*LOC),
        ("sitemap.xml", &*LOC),
        ("llms.txt", &*MD_LINK),
    ] {
        let mut files = vec![site.join(file).expect("a path")];
        let mut seen = BTreeSet::new();
        while let Some(url) = files.pop() {
            if !seen.insert(url.to_string()) || seen.len() > 200 {
                continue;
            }
            let End::Here(url, answer) = crawl.end(&url, true) else {
                continue;
            };
            let on = local(site, &url);
            for m in pattern.captures_iter(answer.body.as_deref().unwrap_or_default()) {
                let href = decode(&m[1]);
                let Ok(mut target) = Url::parse(&href) else {
                    broken.insert(Broken {
                        on: on.clone(),
                        href,
                        problem: "isn't a valid URL".into(),
                    });
                    continue;
                };
                if !same_site(site, &target) {
                    continue;
                }
                target.set_fragment(None);
                if target.path().ends_with(".xml") && file != "llms.txt" {
                    files.push(target);
                    continue;
                }
                // A Markdown twin listed in llms.txt also stands for its page.
                if let Some(page) = target.path().strip_suffix(".md").map(str::to_string) {
                    let mut html = target.clone();
                    html.set_path(if page == "/index" { "/" } else { &page });
                    found.push((on.clone(), href.clone(), html, None, true));
                }
                found.push((on.clone(), href, target, None, true));
            }
        }
    }

    // Read every page breadth first, keyed by where it ends.
    let mut read: BTreeSet<String> = BTreeSet::new();
    let mut queue: VecDeque<Url> = VecDeque::from([home]);
    for (.., url, _, page) in &found {
        if *page {
            queue.push_back(url.clone());
        }
    }
    let mut pages = 0;
    let mut truncated = false;
    let mut page_anchors: BTreeMap<String, BTreeSet<String>> = BTreeMap::new();
    while !queue.is_empty() {
        // Each URL once per batch; one already read or answered isn't fetched again.
        let batch: Vec<(Url, bool)> = queue
            .drain(..)
            .map(|u| (u.to_string(), (u, true)))
            .collect::<BTreeMap<String, (Url, bool)>>()
            .into_values()
            .filter(|(u, _)| !read.contains(u.as_str()))
            .collect();
        crawl.prefetch(&batch);
        for (url, _) in batch {
            let End::Here(url, answer) = crawl.end(&url, true) else {
                continue;
            };
            if !answer.html || !read.insert(url.to_string()) {
                continue;
            }
            if pages >= LIMIT {
                truncated = true;
                continue;
            }
            pages += 1;
            let tags = tags(answer.body.as_deref().unwrap_or_default());
            page_anchors.insert(url.to_string(), anchors(&tags));
            // A `<base href>` is what the page's relative links resolve against.
            let base = tags
                .iter()
                .find(|t| t.name == "base")
                .and_then(|t| t.get("href"))
                .and_then(|h| url.join(h).ok())
                .unwrap_or_else(|| url.clone());
            let on = local(site, &url);
            for (href, is_page) in links(&tags) {
                let href = href.trim().to_string();
                if href.is_empty()
                    || ["mailto:", "tel:", "javascript:", "data:", "blob:"]
                        .iter()
                        .any(|p| href.starts_with(p))
                {
                    continue;
                }
                let Ok(mut target) = base.join(&href) else {
                    broken.insert(Broken {
                        on: on.clone(),
                        href,
                        problem: "isn't a valid URL".into(),
                    });
                    continue;
                };
                if !matches!(target.scheme(), "http" | "https")
                    || !same_site(site, &target)
                    || wordpress_own(site, &target)
                {
                    continue;
                }
                let fragment = target.fragment().filter(|f| !f.is_empty()).map(percent_decode);
                target.set_fragment(None);
                if is_page {
                    queue.push_back(target.clone());
                }
                found.push((on.clone(), href, target, fragment, is_page));
            }
        }
        // The answers this batch's pages read are dropped once their anchors are kept.
        for key in &read {
            if let Some(answer) = crawl.answers.get_mut(key) {
                answer.body = None;
            }
        }
    }

    // Judge every link once per page: assets by status, pages by status and anchor.
    let targets: Vec<(Url, bool)> = found
        .iter()
        .map(|(.., t, _, p)| (t.to_string(), (t.clone(), *p)))
        .collect::<BTreeMap<String, (Url, bool)>>()
        .into_values()
        .take(TARGETS)
        .collect();
    let truncated = truncated || targets.len() >= TARGETS;
    crawl.prefetch(&targets);
    for (on, href, target, fragment, is_page) in found {
        match crawl.end(&target, is_page) {
            End::Away => {}
            End::Broken(problem) => {
                broken.insert(Broken { on, href, problem });
            }
            End::Here(url, answer) => {
                let Some(fragment) = fragment else { continue };
                // `#top` goes to the top of any page; `#:~:text=` is the browser's own; `#file=…`
                // and other `key=value` fragments are state a page's script reads.
                if fragment == "top" || fragment.starts_with(":~:") || fragment.contains('=') {
                    continue;
                }
                let ids = match page_anchors.get(url.as_str()) {
                    Some(ids) => ids.clone(),
                    None if answer.html => {
                        let body = crawl
                            .fetch
                            .get(&url, true)
                            .ok()
                            .and_then(|a| a.body)
                            .unwrap_or_default();
                        let ids = anchors(&tags(&body));
                        page_anchors.insert(url.to_string(), ids.clone());
                        ids
                    }
                    None => continue,
                };
                if !ids.contains(&fragment) {
                    broken.insert(Broken {
                        on,
                        href,
                        problem: format!("{} has no element with id \"{fragment}\"", local(site, &url)),
                    });
                }
            }
        }
    }
    Report {
        pages,
        broken: broken.into_iter().collect(),
        truncated,
        failed: None,
    }
}

fn failed(why: &str) -> Report {
    Report {
        pages: 0,
        broken: Vec::new(),
        truncated: false,
        failed: Some(why.to_string()),
    }
}

/// A URL's path below the site, without a trailing slash: how the report names a page.
fn local(site: &Url, url: &Url) -> String {
    let base = site.path().trim_end_matches('/');
    let path = url.path().strip_prefix(base).unwrap_or(url.path());
    let path = match path.trim_end_matches('/') {
        "" => "/",
        trimmed => trimmed,
    };
    match url.query() {
        Some(q) => format!("{path}?{q}"),
        None => path.to_string(),
    }
}

/// `%XX` escapes to bytes, read as UTF-8 (the text unchanged when they aren't); every other
/// character, `+` included, is itself, as in a URL path or fragment.
pub(super) fn percent_decode(text: &str) -> String {
    let bytes = text.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%'
            && i + 2 < bytes.len()
            && let Some(hex) = text.get(i + 1..i + 3)
            && let Ok(b) = u8::from_str_radix(hex, 16)
        {
            out.push(b);
            i += 3;
        } else {
            out.push(bytes[i]);
            i += 1;
        }
    }
    String::from_utf8(out).unwrap_or_else(|_| text.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;
    use std::sync::Mutex;

    /// A site in memory: path (and query) → (status, content type, body, location). Records what was fetched.
    struct Stub {
        pages: HashMap<String, (u16, &'static str, &'static str, Option<&'static str>)>,
        fetched: Mutex<Vec<String>>,
    }

    impl Fetch for Stub {
        fn get(&self, url: &Url, body: bool) -> Result<Answer, String> {
            let key = match url.query() {
                Some(q) => format!("{}?{q}", url.path()),
                None => url.path().to_string(),
            };
            self.fetched.lock().unwrap().push(key.clone());
            Ok(match self.pages.get(&key) {
                Some((status, kind, text, location)) => Answer {
                    status: *status,
                    location: location.map(str::to_string),
                    body: (body && (200..300).contains(status)).then(|| text.to_string()),
                    html: kind.starts_with("text/html"),
                },
                None => Answer {
                    status: 404,
                    location: None,
                    body: None,
                    html: true,
                },
            })
        }
    }

    const HTML: &str = "text/html";

    fn site(pages: &[(&str, u16, &'static str, &'static str, Option<&'static str>)]) -> Stub {
        Stub {
            pages: pages
                .iter()
                .map(|(p, s, k, b, l)| (p.to_string(), (*s, *k, *b, *l)))
                .collect(),
            fetched: Mutex::new(Vec::new()),
        }
    }

    fn check_at(url: &str, stub: &Stub) -> Report {
        check(&Url::parse(url).unwrap(), stub)
    }

    fn b(on: &str, href: &str, problem: &str) -> Broken {
        Broken {
            on: on.into(),
            href: href.into(),
            problem: problem.into(),
        }
    }

    #[test]
    fn follows_the_site_and_reports_each_broken_link_once() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r##"<a href="/guide">Guide</a> <a href="/gone">Gone</a> <a href="https://elsewhere.test/x">x</a> <img src="/logo.svg"> <a href="#top">Top</a>"##,
                None,
            ),
            (
                "/guide",
                200,
                HTML,
                r##"<h2 id="install">Install</h2><a href="/#missing">Home</a><a href="/old#install">Old</a><a href="/gone">Gone again</a><link rel="stylesheet" href="/site.css">"##,
                None,
            ),
            ("/old", 301, HTML, "", Some("/guide")),
            ("/logo.svg", 200, "image/svg+xml", "", None),
            ("/site.css", 200, "text/css", "", None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.pages, 2);
        assert_eq!(
            report.broken,
            vec![
                b("/", "/gone", "answers 404"),
                b("/guide", "/#missing", "/ has no element with id \"missing\""),
                b("/guide", "/gone", "answers 404")
            ]
        );
    }

    #[test]
    fn reads_the_page_a_redirect_ends_at_and_judges_redirects() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r#"<a href="/docs/x">x</a><a href="/loop">l</a><a href="/bare">b</a><a href="/out">o</a>"#,
                None,
            ),
            ("/docs/x", 301, HTML, "", Some("/docs/x/")),
            ("/docs/x/", 200, HTML, r#"<a href="intro">Intro</a>"#, None),
            ("/docs/x/intro", 200, HTML, "<p>ok</p>", None),
            ("/loop", 302, HTML, "", Some("/loop2")),
            ("/loop2", 302, HTML, "", Some("/loop")),
            ("/bare", 302, HTML, "", None),
            ("/out", 301, HTML, "", Some("https://other.test/")),
        ]);
        let report = check_at("http://site.test/", &stub);
        // The page behind the redirect is read, so its relative link resolves against it.
        assert_eq!(report.pages, 3);
        assert_eq!(
            report.broken,
            vec![
                b("/", "/bare", "answers 302 without a Location"),
                b("/", "/loop", "redirects in a loop or more than five times")
            ]
        );
    }

    #[test]
    fn reads_the_sitemap_and_llms_txt_and_reports_what_they_list_that_is_gone() {
        let stub = site(&[
            ("/", 200, HTML, "<p>Home</p>", None),
            (
                "/wp-sitemap.xml",
                200,
                "application/xml",
                "<sitemapindex><sitemap><loc>http://site.test/wp-sitemap-posts-1.xml</loc></sitemap></sitemapindex>",
                None,
            ),
            (
                "/wp-sitemap-posts-1.xml",
                200,
                "application/xml",
                "<urlset><url><loc>http://site.test/lonely</loc></url><url><loc>http://site.test/gone</loc></url></urlset>",
                None,
            ),
            (
                "/llms.txt",
                200,
                "text/plain",
                "- [Agents](http://site.test/agents.md): for agents\n- [Old](http://site.test/old.md)",
                None,
            ),
            ("/lonely", 200, HTML, r#"<a href="/missing.png">x</a>"#, None),
            ("/agents", 200, HTML, "<p>Agents</p>", None),
            ("/agents.md", 200, "text/markdown", "# Agents", None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.pages, 3);
        assert_eq!(
            report.broken,
            vec![
                b("/llms.txt", "http://site.test/old.md", "answers 404"),
                b("/lonely", "/missing.png", "answers 404"),
                b("/wp-sitemap-posts-1.xml", "http://site.test/gone", "answers 404"),
            ]
        );
    }

    #[test]
    fn leaves_wordpress_own_paths_code_comments_scripts_and_other_sites_alone() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r#"<a href="/wp-admin/">Admin</a> <a href="/wp-login.php?action=x">Log in</a> <pre><a href="/in-code">x</a></pre> <code>&lt;a href="/also">x&lt;/a></code> <!-- <a href="/draft">d</a> --> <script>el.innerHTML = '<img src="' + u + '">';</script> <a href="mailto:a@b.c">Mail</a> <a href="https://other.test/gone">Other</a> <a href="/feedback">F</a>"#,
                None,
            ),
            ("/feedback", 200, HTML, "<p>ok</p>", None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.broken, vec![]);
        // `/feedback` is a page, not WordPress's feed.
        assert_eq!(report.pages, 2);
    }

    #[test]
    fn reads_attributes_however_they_are_written() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r##"<a title="a > b" href="/one">1</a><a href=/two>2</a><link rel=stylesheet href=/s.css><input name="q" id="search"><a href="#search">s</a><meta name="description" content="x"><a href="#description">d</a><code-tabs><a href="/in-tabs">t</a></code-tabs><code>x</code><template><h2 id="hidden">h</h2></template><a href="#hidden">h</a>"##,
                None,
            ),
            ("/one", 200, HTML, "<p>1</p>", None),
            ("/two", 200, HTML, "<p>2</p>", None),
            ("/s.css", 200, "text/css", "", None),
            ("/in-tabs", 200, HTML, "<p>t</p>", None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.pages, 4);
        // `name` on a <meta> and an id inside a <template> are not anchors.
        assert_eq!(
            report.broken,
            vec![
                b("/", "#description", "/ has no element with id \"description\""),
                b("/", "#hidden", "/ has no element with id \"hidden\"")
            ]
        );
    }

    #[test]
    fn a_page_is_html_by_its_type_and_assets_are_not_read() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r#"<a href="/notes.txt">n</a><a href="/blob.txt">b</a>"#,
                None,
            ),
            ("/notes.txt", 200, "text/plain", r#"<a href="/inside-text">x</a>"#, None),
            ("/blob.txt", 200, "text/plain", "plain", None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!((report.pages, report.broken), (1, vec![]));
    }

    #[test]
    fn a_site_below_a_path_checks_only_below_it() {
        let stub = site(&[
            (
                "/docs/",
                200,
                HTML,
                r#"<a href="/elsewhere">x</a><a href="/docs-old/x">y</a><a href="intro">Intro</a>"#,
                None,
            ),
            ("/docs/intro", 200, HTML, "<p>ok</p>", None),
        ]);
        let report = check_at("http://site.test/docs/", &stub);
        assert_eq!((report.pages, report.broken), (2, vec![]));
        assert!(!stub.fetched.lock().unwrap().iter().any(|p| p.starts_with("/docs-old")));
    }

    #[test]
    fn a_home_page_that_is_not_a_page_fails_the_check() {
        let away = site(&[("/", 301, HTML, "", Some("https://www.site.test/"))]);
        assert_eq!(
            check_at("http://site.test/", &away).failed.as_deref(),
            Some("the home page redirects to another site; pass that site's URL")
        );
        let down = site(&[]);
        assert_eq!(
            check_at("http://site.test/", &down).failed.as_deref(),
            Some("the home page answers 404")
        );
    }

    #[test]
    fn decodes_fragments_and_attribute_references_and_skips_script_state() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r#"<h2 id="café">C</h2><a href="/#caf%C3%A9">ok</a><a href="/?a=1&amp;b=2#x">q</a><a href="/#file=first%2Fapp">f</a><a href="/#:~:text=hi">t</a>"#,
                None,
            ),
            ("/?a=1&b=2", 200, HTML, r#"<a name="x"></a>"#, None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.broken, vec![]);
    }

    #[test]
    fn reads_a_page_once_and_skips_wordpress_discovery_links() {
        let stub = site(&[
            (
                "/",
                200,
                HTML,
                r#"<link rel="shortlink" href="/?p=9"><link rel='https://api.w.org/' href="/wp-json/"><link rel="alternate" type="text/markdown" href="/index.md"><a href="/a">A</a><a href="/a">A again</a><a href="/b">B</a>"#,
                None,
            ),
            ("/index.md", 200, "text/markdown", "# Home", None),
            ("/a", 200, HTML, r#"<a href="/gone">x</a>"#, None),
            ("/b", 200, HTML, r#"<link rel="stylesheet" href="/missing.css">"#, None),
        ]);
        let report = check_at("http://site.test/", &stub);
        assert_eq!(report.pages, 3);
        assert_eq!(
            report.broken,
            vec![b("/a", "/gone", "answers 404"), b("/b", "/missing.css", "answers 404")]
        );
        let fetched = stub.fetched.lock().unwrap();
        assert_eq!(fetched.iter().filter(|p| *p == "/a").count(), 1, "{fetched:?}");
        assert!(!fetched.iter().any(|p| p == "/?p=9" || p == "/wp-json/"));
    }
}
