//! `spacepress push [project-dir] [--site <url>] [--dry-run]`: deploy this project to a
//! WordPress site that runs the SpacePress plugin.
//!
//! The project is compiled here into a build store under `.sp/store`, against the schema
//! snapshot and migrations of the build the site runs now, so the migration it carries is the
//! difference from what is live. The site then gets the build's manifest and whichever of its two
//! trees (the runtime, the app) it does not already hold, says what installing it would change, and
//! installs it and makes it live.
use super::remote::{self, Api, Refused};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::path::{Path, PathBuf};
use std::process::ExitCode;

const USAGE: &str = "usage: spacepress push [project-dir] [--site <url>] [--dry-run]\n       sign in first with: spacepress login <site-url>   (in CI: SP_APP_PASSWORD=user:application-password)";

pub(crate) fn cli(args: &[String]) -> ExitCode {
    match run(args) {
        Ok(()) => ExitCode::SUCCESS,
        Err(e) => {
            eprintln!("{e}");
            ExitCode::from(1)
        }
    }
}

fn hex(bytes: impl AsRef<[u8]>) -> String {
    bytes.as_ref().iter().map(|b| format!("{b:02x}")).collect()
}

fn short(id: &str) -> &str {
    &id[..id.len().min(12)]
}

fn run(args: &[String]) -> Result<(), String> {
    let (mut project, mut site, mut dry) = (PathBuf::from("."), None, false);
    let mut it = args.iter();
    while let Some(a) = it.next() {
        match a.as_str() {
            "--site" => site = Some(it.next().ok_or(format!("--site needs a URL\n{USAGE}"))?.clone()),
            "--dry-run" => dry = true,
            other if other.starts_with('-') => return Err(format!("unknown option {other}\n{USAGE}")),
            other => project = PathBuf::from(other),
        }
    }
    let project = project
        .canonicalize()
        .map_err(|e| format!("{}: {e}", project.display()))?;
    if !project.join("spacepress.config.ts").is_file() {
        return Err(format!("no spacepress.config.ts in {}\n{USAGE}", project.display()));
    }
    let site = match site.or_else(|| remote::linked(&project)) {
        Some(site) => remote::normalize(&site)?,
        None => {
            return Err(format!(
                "this project has no site to deploy to. Run spacepress login <site-url> here, or pass --site <url>\n{USAGE}"
            ));
        }
    };
    let api = Api::connect(&site)?;
    let mut info = api.get("/site", &[]).map_err(|e| e.to_string())?;
    // The site's plugin is kept the one this command carries: a live app turns off plugin uploads in
    // wp-admin, so this is how it is updated. A newer plugin is left alone.
    let ours = super::plugin::hash();
    if info["pluginHash"].as_str() != Some(ours.as_str()) {
        let theirs = info["plugin"].as_str().unwrap_or("0");
        if !crate::wp_plugins::version::at_least(env!("CARGO_PKG_VERSION"), theirs) {
            return Err(format!(
                "{site} runs SpacePress plugin {theirs}, newer than this spacepress ({}). Update spacepress, then push again.",
                env!("CARGO_PKG_VERSION")
            ));
        }
        // A dry run changes nothing on the site but the uploaded build, which is not live. A plugin
        // that doesn't say its receiver keeps uploads private and runs no app code is not sent any.
        if dry {
            println!("--dry-run: a push would update the SpacePress plugin on the site from {theirs}");
            if info["dryRun"] != true {
                println!(
                    "--dry-run: the site's SpacePress plugin can't take a dry run without changing the site, so nothing was uploaded. Run spacepress push to update it."
                );
                return Ok(());
            }
        } else {
            let zip = super::plugin::archive().map_err(|e| e.to_string())?;
            let sha = hex(Sha256::digest(&zip));
            api.upload("/plugin", &[("sha256", &sha)], &zip)
                .map_err(|e| format!("updating the SpacePress plugin on {site}: {e}"))?;
            info = api.get("/site", &[]).map_err(|e| e.to_string())?;
            if info["pluginHash"].as_str() != Some(ours.as_str()) {
                return Err(format!(
                    "{site} still runs another SpacePress plugin after the update. Upload the one `spacepress plugin` writes in wp-admin."
                ));
            }
            println!("updated the SpacePress plugin on the site");
        }
    }
    if info["protocol"].as_u64() != Some(remote::PROTOCOL) {
        return Err(format!(
            "{site}'s SpacePress plugin speaks deploy protocol {}, and this spacepress speaks {}",
            info["protocol"],
            remote::PROTOCOL
        ));
    }
    if let Some(closed) = info["closed"].as_str() {
        return Err(closed.to_string());
    }
    // What the site wants its owner to know, such as a build store a web server could serve.
    for warning in info["warnings"].as_array().into_iter().flatten() {
        if let Some(text) = warning.as_str() {
            eprintln!("warning: {text}");
        }
    }

    // The build the site runs now is what this one is compared with.
    let work = tempfile::tempdir().map_err(|e| e.to_string())?;
    let live = info["live"]["build"].as_str().map(str::to_owned);
    // The build deployed last, which is the live one unless an earlier build was made live again:
    // the database is at its schema either way.
    let baseline = match &info["baseline"].as_str().map(str::to_owned) {
        None => None,
        Some(build) => {
            let answer = api
                .get(&format!("/builds/{build}/baseline"), &[])
                .map_err(|e| e.to_string())?;
            let dir = work.path().join("baseline");
            let write = |path: PathBuf, text: &Value| -> Result<(), String> {
                let text = text.as_str().ok_or("the site's baseline is incomplete")?;
                std::fs::write(&path, text).map_err(|e| format!("{}: {e}", path.display()))
            };
            std::fs::create_dir_all(dir.join("migrations")).map_err(|e| e.to_string())?;
            write(dir.join("snapshot.json"), &answer["snapshot"])?;
            for (name, content) in answer["migrations"].as_object().into_iter().flatten() {
                if name.contains('\\') || name.starts_with('.') || name.contains("..") {
                    return Err(format!("the site sent a migration with an unsafe name ({name})"));
                }
                let Some((id, file)) = name.split_once('/') else {
                    return Err(format!("the site sent a migration with an unsafe name ({name})"));
                };
                if file.contains('/')
                    || !crate::migrate::is_migration_id(id)
                    || !matches!(file, "ops.json" | "migration.sql")
                {
                    return Err(format!("the site sent a migration with an unsafe name ({name})"));
                }
                let dest = dir.join("migrations").join(id).join(file);
                if let Some(parent) = dest.parent() {
                    std::fs::create_dir_all(parent).map_err(|e| e.to_string())?;
                }
                write(dest, content)?;
            }
            Some((build.clone(), dir))
        }
    };
    let sources = work.path().join("sources");
    let render = |d: crate::diag::Diag| crate::diag::render(&d, &project).trim_end().to_string();
    crate::bundle::sources(&project, &sources).map_err(render)?;
    let store = project.join(".sp/store");
    let built = crate::store::compile_to_store_against(
        &sources,
        &store,
        &sources,
        baseline.as_ref().map(|(id, dir)| (id.as_str(), dir.as_path())),
    )
    .map_err(render)?;
    let manifest = std::fs::read_to_string(store.join("build").join(format!("{}.json", built.build_id)))
        .map_err(|e| format!("the compiled build's manifest: {e}"))?;
    println!("compiled {} (build {})", built.manifest.name, short(&built.build_id));
    // What this build needs from the site: the plugins its compiled manifest names, at this
    // compiler's pinned versions and checksums.
    let server = info["server"].as_array().cloned().unwrap_or_default();
    if !server.is_empty() {
        let lines: Vec<String> = server
            .iter()
            .map(|c| {
                format!(
                    "{} {} (found {})",
                    c["label"].as_str().unwrap_or("?"),
                    c["required"].as_str().unwrap_or("?"),
                    c["found"].as_str().unwrap_or("none")
                )
            })
            .collect();
        return Err(format!(
            "{site} can't run SpacePress: it needs {}. That is changed on the server, not by a deployment.",
            lines.join(", ")
        ));
    }
    let hosted: Value = std::fs::read(store.join("app").join(&built.app_hash).join("manifest.json"))
        .ok()
        .and_then(|bytes| serde_json::from_slice(&bytes).ok())
        .ok_or("the compiled app has no manifest")?;
    let mut pins = serde_json::Map::new();
    let mut missing = Vec::new();
    for plugin in crate::platform::plugins(true) {
        if hosted["requires"].get(&plugin.slug).is_none() {
            continue;
        }
        let found = &info["installed"][&plugin.slug];
        let ok = found["active"] == true
            && found["version"]
                .as_str()
                .is_some_and(|v| crate::wp_plugins::version::at_least(v, &plugin.version));
        if !ok {
            missing.push(format!(
                "{} {} ({})",
                plugin.slug,
                plugin.version,
                match found["version"].as_str() {
                    Some(v) if found["active"] == true => format!("found {v}"),
                    Some(v) => format!("found {v}, inactive"),
                    None => "not installed".to_string(),
                }
            ));
            pins.insert(
                plugin.slug,
                json!({ "version": plugin.version, "sha256": plugin.sha256 }),
            );
        }
    }
    // The plugins a build needs are not a choice: push installs them, pinned by checksum.
    if !missing.is_empty() {
        println!("the site is missing plugins this app needs: {}", missing.join(", "));
        // The site would take the build, but couldn't plan it: the runtime mounts no app whose plugins
        // are missing, so a dry run says what a push would do and ends here, before uploading.
        if dry {
            println!(
                "--dry-run: a push would install them, then upload and plan this build; nothing changed on {site}"
            );
            return Ok(());
        }
        let answer = api
            .post("/requirements", &json!({ "pins": pins }), &[])
            .map_err(|e| e.to_string())?;
        for installed in answer["installed"].as_array().into_iter().flatten() {
            println!("installed {} on the site", installed.as_str().unwrap_or("a plugin"));
        }
        // The app may run now that its plugins are back.
        info = api.get("/site", &[]).map_err(|e| e.to_string())?;
    }

    // The same source compiles to the same two trees whatever it was compared with. That is only
    // "nothing to deploy" when the site is running them: a site that names this code as live and
    // can't mount it (a tree is gone from its store) gets it again.
    if info["live"]["app"].as_str() == Some(built.app_hash.as_str())
        && info["live"]["runtime"].as_str() == Some(built.runtime_revision.as_str())
    {
        if live.is_some() && info["mounted"]["build"].as_str() == live.as_deref() {
            println!("{site} already runs this code; nothing to deploy");
            return Ok(());
        }
        println!(
            "{site} has this code and is not running it ({}); deploying it again",
            info["mountError"].as_str().unwrap_or("the build did not mount")
        );
    }

    let created = api
        .post("/deployments", &json!({ "manifest": manifest }), &[])
        .map_err(|e| e.to_string())?;
    let (Some(id), Some(token)) = (created["deployment"].as_str(), created["token"].as_str()) else {
        return Err(format!("{site} did not open a deployment"));
    };
    if created["build"].as_str() != Some(built.build_id.as_str()) {
        return Err(format!(
            "{site} read the build as {}, not {}; nothing was deployed",
            created["build"].as_str().unwrap_or("nothing"),
            built.build_id
        ));
    }
    let part = info["part"].as_u64().unwrap_or(1 << 20).clamp(64 * 1024, 16 << 20) as usize;
    let trees = [("runtime", &built.runtime_revision), ("app", &built.app_hash)];
    // A tree the live build names and the site has lost would bring that build back the moment it is
    // stored, with no deployment: a dry run doesn't send it.
    if dry {
        for (kind, tree) in trees {
            if created["need"][kind] == true && info["live"][kind].as_str() == Some(tree.as_str()) {
                println!(
                    "--dry-run: the site has lost the {kind} tree its live build runs, and uploading it would run that build again; nothing was uploaded. Run spacepress push to deploy."
                );
                return Ok(());
            }
        }
    }
    for (kind, tree) in trees {
        if created["need"][kind] == true {
            upload(&api, id, kind, &store.join(kind).join(tree), part)?;
        } else {
            println!("the site already has this {kind} tree");
        }
    }

    let header = format!("{id}:{token}");
    let plan = api
        .get(
            &format!("/deployments/{id}/plan"),
            &[("X-SpacePress-Deployment", &header)],
        )
        .map_err(|e| e.to_string())?;
    let steps = plan["steps"].as_array().cloned().unwrap_or_default();
    if steps.is_empty() {
        println!("installing it changes nothing on the site besides the code");
    } else {
        println!("installing it will:");
        for step in &steps {
            println!("  {}", step_line(step));
        }
    }
    let refusals = plan["refusals"].as_array().cloned().unwrap_or_default();
    if !refusals.is_empty() {
        let lines: Vec<String> = refusals
            .iter()
            .map(|r| {
                let fix = r["fix"].as_str().map_or(String::new(), |fix| format!(" ({fix})"));
                format!("  {}{fix}", r["message"].as_str().unwrap_or("refused"))
            })
            .collect();
        // The site's fix text is written for `spacepress build`, which keeps migrations in the project.
        // A push keeps none there, so the last line says what to do here.
        return Err(format!(
            "{site} refuses this build; nothing changed there:\n{}\nChange spacepress.config.ts and push again: spacepress push plans the migration on the site and writes none into the project.",
            lines.join("\n")
        ));
    }
    if dry {
        println!(
            "--dry-run: the build is uploaded and not live; {site} still runs {}",
            live.as_deref()
                .map_or("no app".to_string(), |b| format!("build {}", short(b)))
        );
        return Ok(());
    }
    let done = api
        .post(
            &format!("/deployments/{id}/activate"),
            &json!({}),
            &[("X-SpacePress-Deployment", &header)],
        )
        .map_err(|e: Refused| match e.data["live"].as_str() {
            Some(build) => format!("{e}\n{site} still runs build {}", short(build)),
            None if e.code == "sp_not_installed" => format!("{e}\n{site} runs no app"),
            None => e.to_string(),
        })?;
    for migration in done["migrations"].as_array().into_iter().flatten() {
        println!("applied migration {}", migration.as_str().unwrap_or("?"));
    }
    if let Some(seed) = done["seed"].as_object() {
        println!("seeded content: {}", Value::Object(seed.clone()));
    }
    println!(
        "live: {} now runs {} (build {})",
        done["home"].as_str().unwrap_or(&site),
        built.manifest.name,
        short(&built.build_id)
    );
    Ok(())
}

/// One line for a step of the site's install plan; a kind this command doesn't know is shown as is.
fn step_line(step: &Value) -> String {
    let id = step["id"].as_str().unwrap_or("");
    let detail = id.split_once(':').map_or(id, |(_, rest)| rest);
    let text = match step["kind"].as_str().unwrap_or("") {
        "system-tables" => "create or upgrade SpacePress's own tables".to_string(),
        "identity-schema" => "create or upgrade the sign-in tables".to_string(),
        "migration" => format!("run migration {detail}"),
        "front-page" => "set the site's front page".to_string(),
        "search-shape" => "rebuild the search index for the new searchable fields".to_string(),
        "rewrite-rules" => "rebuild WordPress's URL rules".to_string(),
        kind if kind.starts_with("page.") => format!("{} the page for {detail}", &kind[5..]),
        kind => format!("{kind} {id}"),
    };
    if step["state"] == "started" {
        format!("{text} (a previous deployment started this and did not finish)")
    } else {
        text
    }
}

/// ZIP one stored tree and send it in parts the site's PHP accepts.
fn upload(api: &Api, id: &str, kind: &str, tree: &Path, part: usize) -> Result<(), String> {
    let files = crate::store::tree_files(tree).map_err(|e| format!("{}: {e}", tree.display()))?;
    let zip = super::plugin::zip(files.iter().map(|(p, b)| (p.as_str(), b.as_slice())), false)
        .map_err(|e| format!("can't archive the {kind} tree: {e}"))?;
    let sha = hex(Sha256::digest(&zip));
    let total = zip.len().div_ceil(part).max(1);
    println!(
        "uploading the {kind} tree: {} files, {:.1} MB in {total} part{}",
        files.len(),
        zip.len() as f64 / 1_048_576.0,
        if total == 1 { "" } else { "s" }
    );
    for (index, chunk) in zip.chunks(part).enumerate() {
        let (index, total) = (index.to_string(), total.to_string());
        api.upload(
            &format!("/deployments/{id}/trees/{kind}"),
            &[("index", &index), ("total", &total), ("sha256", &sha)],
            chunk,
        )
        .map_err(|e| format!("uploading the {kind} tree: {e}"))?;
    }
    Ok(())
}
