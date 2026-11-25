//! `spacepress login <site-url> [project-dir] [--no-browser]` and `spacepress logout <site-url>`.
//!
//! Signing in uses WordPress's own Authorize Application screen: the site's owner approves an
//! application password in the browser, and WordPress sends it to this command on 127.0.0.1 (core
//! allows that redirect without HTTPS). The password is kept in first's config directory with
//! owner-only permissions, and a project the command runs in remembers the site.
use super::remote;
use std::io::{BufRead as _, BufReader, Write as _};
use std::net::TcpListener;
use std::path::PathBuf;
use std::process::ExitCode;
use std::time::{Duration, Instant};

const USAGE: &str =
    "usage: spacepress login <site-url> [project-dir] [--no-browser]\n       spacepress logout <site-url>";
/// Identifies this command to WordPress, which lists application passwords by app.
const APP_ID: &str = "0d1f6c5e-3b55-4f0e-9c2a-6a1e5f1b7a10";
const WAIT: Duration = Duration::from_secs(600);

pub(crate) fn cli(command: &str, args: &[String]) -> ExitCode {
    let result = match command {
        "logout" => logout(args),
        _ => login(args),
    };
    match result {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{e}");
            ExitCode::from(1)
        }
    }
}

fn logout(args: &[String]) -> Result<(), String> {
    let [site] = args else {
        return Err(USAGE.into());
    };
    let site = remote::normalize(site)?;
    if remote::forget(&site)? {
        println!(
            "forgot the credential for {site} on this machine. To revoke it on the site: Users, Profile, Application Passwords."
        );
    } else {
        println!("no credential for {site} is stored on this machine");
    }
    Ok(())
}

/// 128 bits from the OS-seeded keys of two `RandomState`s: a one-time value the redirect must carry.
fn state() -> String {
    use std::hash::{BuildHasher as _, Hasher as _};
    (0..2)
        .map(|_| {
            format!(
                "{:016x}",
                std::collections::hash_map::RandomState::new().build_hasher().finish()
            )
        })
        .collect()
}

fn encode(value: &str) -> String {
    url::form_urlencoded::byte_serialize(value.as_bytes()).collect()
}

fn open_browser(url: &str) {
    let (program, args): (&str, &[&str]) = if cfg!(target_os = "macos") {
        ("open", &[])
    } else if cfg!(windows) {
        ("cmd", &["/C", "start", ""])
    } else {
        ("xdg-open", &[])
    };
    let _ = std::process::Command::new(program)
        .args(args)
        .arg(url)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .spawn();
}

fn login(args: &[String]) -> Result<(), String> {
    let (mut site, mut project, mut browser) = (None, None, true);
    for a in args {
        match a.as_str() {
            "--no-browser" => browser = false,
            other if other.starts_with('-') => return Err(format!("unknown option {other}\n{USAGE}")),
            other if site.is_none() => site = Some(other.to_string()),
            other if project.is_none() => project = Some(PathBuf::from(other)),
            _ => return Err(USAGE.into()),
        }
    }
    let site = remote::normalize(&site.ok_or(USAGE)?)?;
    let found = remote::discover(&site)?;
    if !found.plugin {
        return Err(remote::no_plugin(&site));
    }
    let Some(authorize) = found.authorize else {
        return Err(format!(
            "{site} does not accept application passwords. WordPress turns them on only over HTTPS (or when WP_ENVIRONMENT_TYPE is local)."
        ));
    };
    let listener = TcpListener::bind(("127.0.0.1", 0)).map_err(|e| format!("can't listen on 127.0.0.1: {e}"))?;
    let port = listener.local_addr().map_err(|e| e.to_string())?.port();
    let state = state();
    let callback = format!("http://127.0.0.1:{port}/callback?state={state}");
    let name = format!(
        "SpacePress CLI{}",
        std::env::var("HOSTNAME")
            .ok()
            .filter(|h| !h.is_empty())
            .map_or(String::new(), |h| format!(" on {h}"))
    );
    let separator = if authorize.contains('?') { '&' } else { '?' };
    let approve = format!(
        "{authorize}{separator}app_name={}&app_id={APP_ID}&success_url={}",
        encode(&name),
        encode(&callback)
    );
    println!(
        "Approve this command line on {} ({site}):\n\n  {approve}\n",
        if found.name.is_empty() { "the site" } else { &found.name }
    );
    if browser {
        open_browser(&approve);
    }
    println!("waiting for the approval (10 minutes)...");
    let (user, password) = wait(&listener, &state)?;
    let api = remote::Api::new(&site, &found.rest, &format!("{user}:{password}"));
    let info = api
        .get("/site", &[])
        .map_err(|e| format!("{site} did not accept the new credential: {e}"))?;
    let file = remote::store(&site, &found.rest, &user, &password)?;
    println!("signed in to {site} as {user} (credential kept in {})", file.display());
    let project = project.unwrap_or_else(|| PathBuf::from("."));
    if project.join("spacepress.config.ts").is_file() {
        remote::link(&project, &site)?;
        println!("this project now deploys to {site}: run spacepress push");
    } else {
        println!("in a project, deploy with: spacepress push --site {site}");
    }
    if info["ready"] != true {
        println!(
            "the site still needs plugins SpacePress depends on: spacepress push installs them, or use the button at {}",
            info["setup"].as_str().unwrap_or("SpacePress in wp-admin")
        );
    }
    Ok(())
}

/// Wait for WordPress's redirect to the callback and answer the browser. Other requests to the
/// port (a favicon, a wrong state) are answered and ignored.
fn wait(listener: &TcpListener, state: &str) -> Result<(String, String), String> {
    listener.set_nonblocking(true).map_err(|e| e.to_string())?;
    let deadline = Instant::now() + WAIT;
    loop {
        let (mut stream, _) = match listener.accept() {
            Ok(connection) => connection,
            Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                if Instant::now() > deadline {
                    return Err("no approval arrived in 10 minutes. Run spacepress login again.".into());
                }
                std::thread::sleep(Duration::from_millis(100));
                continue;
            }
            Err(e) => return Err(format!("the local callback failed: {e}")),
        };
        let _ = stream.set_nonblocking(false);
        let _ = stream.set_read_timeout(Some(Duration::from_secs(5)));
        let mut line = String::new();
        if BufReader::new(&stream).read_line(&mut line).is_err() {
            continue;
        }
        let target = line.split_whitespace().nth(1).unwrap_or("");
        let parsed = url::Url::parse(&format!("http://127.0.0.1{target}")).ok();
        let query: std::collections::HashMap<String, String> = parsed
            .as_ref()
            .map(|u| u.query_pairs().into_owned().collect())
            .unwrap_or_default();
        let ours = parsed.as_ref().is_some_and(|u| u.path() == "/callback")
            && query.get("state").map(String::as_str) == Some(state);
        let outcome = if !ours {
            None
        } else if let (Some(user), Some(password)) = (query.get("user_login"), query.get("password")) {
            Some(Ok((user.clone(), password.clone())))
        } else {
            Some(Err(
                "the request was not approved on the site. Run spacepress login again to retry.".to_string(),
            ))
        };
        let (status, text) = match &outcome {
            Some(Ok(_)) => (
                "200 OK",
                "Signed in. You can close this tab and go back to the terminal.",
            ),
            Some(Err(_)) => ("200 OK", "The request was not approved. You can close this tab."),
            None => ("404 Not Found", "Nothing here."),
        };
        let body = format!("<!doctype html><meta charset=\"utf-8\"><title>SpacePress</title><p>{text}</p>");
        let _ = write!(
            stream,
            "HTTP/1.1 {status}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {}\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n{body}",
            body.len()
        );
        let _ = stream.flush();
        if let Some(outcome) = outcome {
            return outcome;
        }
    }
}
