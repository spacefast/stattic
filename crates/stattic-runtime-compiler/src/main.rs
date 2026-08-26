use serde::Deserialize;
use sha2::{Digest, Sha256};
use stattic_runtime_core::{
    finalize_site, read_site_finalize_input, write_site_finalize_error, write_site_finalize_output,
    FinalizeError, RuntimeDiagnosticSeverity, SiteFinalizeInput, SITE_FINALIZE_INPUT_FORMAT,
};
use std::env;
use std::fs;
use std::io::{self, Read};
use std::path::{Path, PathBuf};

#[derive(Debug)]
enum Command {
    Finalize(FinalizeArgs),
    Prepare {
        source: String,
        bytecode: String,
        capabilities: Option<String>,
        generated_source: Option<String>,
    },
    Invoke,
    ServiceBroker,
    MarkdownToHtml,
    ContentCompile {
        config: PathBuf,
        output: PathBuf,
        php_only: bool,
        inspect: bool,
    },
    RunHook {
        manifest: PathBuf,
        id: String,
    },
    SelfTest,
}

#[derive(Debug, Default)]
struct FinalizeArgs {
    input: PathBuf,
    version_root: Option<String>,
    output: Option<PathBuf>,
    dry_run: bool,
}

#[derive(Debug)]
enum CliError {
    Usage(String),
    Finalize(FinalizeError),
    Json(serde_json::Error),
    Io(io::Error),
    ContentCompile(String),
    SelfTest(String),
}

impl std::fmt::Display for CliError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Usage(message) => f.write_str(message),
            Self::Finalize(error) => write!(f, "{error}"),
            Self::Json(error) => write!(f, "{error}"),
            Self::Io(error) => write!(f, "{error}"),
            Self::ContentCompile(error) => f.write_str(error),
            Self::SelfTest(message) => f.write_str(message),
        }
    }
}

impl std::error::Error for CliError {}

impl From<FinalizeError> for CliError {
    fn from(error: FinalizeError) -> Self {
        Self::Finalize(error)
    }
}

impl From<serde_json::Error> for CliError {
    fn from(error: serde_json::Error) -> Self {
        Self::Json(error)
    }
}

impl From<io::Error> for CliError {
    fn from(error: io::Error) -> Self {
        Self::Io(error)
    }
}

fn main() {
    if let Err(error) = run() {
        eprintln!("stattic-runtime: {error}");
        std::process::exit(2);
    }
}

fn run() -> Result<(), CliError> {
    let command = parse_args(env::args().skip(1))?;
    match command {
        Command::Finalize(args) => finalize_site_command(args)?,
        Command::Prepare {
            source,
            bytecode,
            capabilities,
            generated_source,
        } => exit_with_status(stattic_zero_runner::prepare(
            &source,
            &bytecode,
            capabilities.as_deref(),
            generated_source.as_deref(),
        )),
        Command::Invoke => stattic_zero_runner::run_stdio(),
        Command::ServiceBroker => stattic_zero_runner::run_service_broker_stdio(),
        Command::MarkdownToHtml => markdown_to_html()?,
        Command::ContentCompile {
            config,
            output,
            php_only,
            inspect,
        } => content_compile(config, output, php_only, inspect)?,
        Command::RunHook { manifest, id } => run_content_hook(manifest, id)?,
        Command::SelfTest => self_test()?,
    }
    Ok(())
}

#[derive(Debug, Deserialize)]
struct HookManifest {
    hooks: Vec<payloadwp::ir::Hook>,
}

fn run_content_hook(manifest: PathBuf, id: String) -> Result<(), CliError> {
    let manifest: HookManifest = serde_json::from_slice(&fs::read(&manifest)?)?;
    let hook = manifest
        .hooks
        .iter()
        .find(|hook| hook.id == id)
        .ok_or_else(|| CliError::ContentCompile(format!("hook {id} is not present")))?;
    if !hook.capabilities.is_empty() {
        return Err(CliError::ContentCompile(format!(
            "hook {id} requires unsupported capabilities: {}",
            hook.capabilities.join(", ")
        )));
    }
    let mut input = String::new();
    io::stdin().read_to_string(&mut input)?;
    let arguments = serde_json::from_str(&input)?;
    let result = payloadwp::javascript::run_executable(&hook.source, &arguments, &hook.event)
        .map_err(|error| CliError::ContentCompile(error.to_string()))?;
    println!("{}", serde_json::to_string(&result)?);
    Ok(())
}

fn content_compile(
    config: PathBuf,
    output: PathBuf,
    php_only: bool,
    inspect: bool,
) -> Result<(), CliError> {
    let report = payloadwp::compile(payloadwp::CompileOptions {
        config,
        output,
        php_only,
        inspect_only: inspect,
    })
    .map_err(|error| CliError::ContentCompile(error.to_string()))?;
    if inspect {
        println!("{}", report.ir_json);
    } else {
        println!(
            "{{\"format\":\"spacefast.content.compile.v1\",\"collections\":{},\"fields\":{},\"hooks\":{},\"output\":{}}}",
            report.collections,
            report.fields,
            report.hooks,
            serde_json::to_string(&report.output.to_string_lossy().as_ref())?
        );
    }
    Ok(())
}

fn markdown_to_html() -> Result<(), CliError> {
    const MAX_BYTES: usize = 2 * 1024 * 1024;
    let mut source = String::new();
    io::stdin()
        .take((MAX_BYTES + 1) as u64)
        .read_to_string(&mut source)?;
    if source.len() > MAX_BYTES {
        return Err(CliError::Usage("markdown input exceeds 2 MiB".to_string()));
    }
    print!(
        "{}",
        stattic_runtime_core::content::markdown_fragment(&source)
    );
    Ok(())
}

fn exit_with_status(status: i32) {
    if status != 0 {
        std::process::exit(status);
    }
}

/// Runs the filesystem-rooted site finalize. `--dry-run` executes the
/// complete pipeline but discards the stage instead of publishing the
/// immutable version; the output JSON goes to `--output` or stdout.
fn finalize_site_command(args: FinalizeArgs) -> Result<(), CliError> {
    let output_path = args.output.clone();
    let result = (|| -> Result<(), FinalizeError> {
        let mut input = read_site_finalize_input(&args.input)?;
        if let Some(version_root) = args.version_root {
            input.version_root = version_root;
        }
        let output = finalize_site(input, args.dry_run)?;
        write_site_finalize_output(output, output_path.as_deref())
    })();
    if let Err(error) = result {
        if let Some(path) = output_path.as_deref() {
            write_site_finalize_error(&error, path)?;
        }
        return Err(error.into());
    }
    Ok(())
}

/// The one document the self-test finalizes: enough HTML for the decoration
/// pass to have real work to do, small enough to stay well inside the five
/// second deadline `installer.php` allows the probe.
const SELF_TEST_DOCUMENT: &[u8] = b"<!doctype html>\n<title>self test</title>\n<p>ok</p>\n";

/// Proves the binary can run its real job — a site finalize — end to end
/// before printing the probe line installers and image builds assert on.
///
/// The finalize is a dry run in a throwaway directory: it walks the whole
/// pipeline (CAS staging, the HTML decoration pass, response tables, catalog,
/// version artifacts) and discards the stage instead of publishing a version.
/// Nothing outside the temporary root is read or written.
fn self_test() -> Result<(), CliError> {
    let root = env::temp_dir().join(format!("stattic-runtime-self-test-{}", std::process::id()));
    let _ = fs::remove_dir_all(&root);
    let result = self_test_finalize(&root);
    let _ = fs::remove_dir_all(&root);
    result?;
    stattic_zero_runner::self_test().map_err(CliError::SelfTest)?;
    println!(
        "{{\"format\":\"stattic.runtime.self-test.v1\",\"version\":\"{}\",\"abi\":\"{}\"}}",
        env!("CARGO_PKG_VERSION"),
        stattic_zero_runner::RUNNER_ABI
    );
    Ok(())
}

/// Stages one document in a throwaway per-space CAS the way ingest does, then
/// finalizes it as a dry run and checks the result the way the control plane
/// does: one file published, no error diagnostic.
fn self_test_finalize(root: &Path) -> Result<(), CliError> {
    let sha256 = format!("{:x}", Sha256::digest(SELF_TEST_DOCUMENT));
    let private = root.join("storage");
    let blobs = private.join(format!("spaces/s/blobs/{}", &sha256[..2]));
    fs::create_dir_all(&blobs).map_err(|error| self_test_io(&blobs, &error))?;
    let blob = blobs.join(&sha256);
    fs::write(&blob, SELF_TEST_DOCUMENT).map_err(|error| self_test_io(&blob, &error))?;

    let output = finalize_site(
        SiteFinalizeInput {
            format: SITE_FINALIZE_INPUT_FORMAT.to_string(),
            version_root: private.to_string_lossy().into_owned(),
            space_id: "s".to_string(),
            version_id: "v".to_string(),
            upload_id: None,
            generated_at: "1970-01-01T00:00:00Z".to_string(),
            session: serde_json::json!({
                "manifest": [{
                    "path": "index.html",
                    "size": SELF_TEST_DOCUMENT.len(),
                    "sha256": sha256,
                    "contentType": "text/html",
                }],
                "accepted": { sha256: SELF_TEST_DOCUMENT.len() },
                "metadata": { "mode": "website" },
            }),
            body: serde_json::json!({ "serving": { "config": {} } }),
            zero_endpoints: Vec::new(),
            zero_runs: Vec::new(),
        },
        true,
    )?;
    if output.file_count != 1 {
        return Err(CliError::SelfTest(format!(
            "self-test finalize published {} files, expected 1",
            output.file_count
        )));
    }
    if let Some(diagnostic) = output
        .diagnostics
        .iter()
        .find(|diagnostic| diagnostic.severity == RuntimeDiagnosticSeverity::Error)
    {
        return Err(CliError::SelfTest(format!(
            "self-test finalize reported {}: {}",
            diagnostic.code, diagnostic.message
        )));
    }
    Ok(())
}

fn self_test_io(path: &Path, error: &std::io::Error) -> CliError {
    CliError::SelfTest(format!(
        "self-test could not use {}: {error}",
        path.display()
    ))
}

fn parse_args(args: impl IntoIterator<Item = String>) -> Result<Command, CliError> {
    let mut args = args.into_iter();
    let Some(command) = args.next() else {
        return Err(CliError::Usage(usage()));
    };
    if command == "--self-test" {
        return Ok(Command::SelfTest);
    }
    match command.as_str() {
        "finalize" => Ok(Command::Finalize(parse_finalize_args(args)?)),
        "prepare" => {
            let values = args.collect::<Vec<_>>();
            match values.as_slice() {
                [source, bytecode, optional @ ..] if optional.len() <= 2 => Ok(Command::Prepare {
                    source: source.clone(),
                    bytecode: bytecode.clone(),
                    capabilities: optional.first().cloned(),
                    generated_source: optional.get(1).cloned(),
                }),
                _ => Err(CliError::Usage(usage())),
            }
        }
        "invoke" => no_operands(args, Command::Invoke),
        "service-broker" => no_operands(args, Command::ServiceBroker),
        "markdown-to-html" => no_operands(args, Command::MarkdownToHtml),
        "content-compile" => parse_content_compile_args(args),
        "run-hook" => parse_run_hook_args(args),
        "--help" | "-h" | "help" => Err(CliError::Usage(usage())),
        _ => Err(CliError::Usage(format!(
            "unknown command {command:?}\n\n{}",
            usage()
        ))),
    }
}

fn parse_run_hook_args(args: impl IntoIterator<Item = String>) -> Result<Command, CliError> {
    let mut manifest = None;
    let mut id = None;
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--manifest" => manifest = Some(next_path(&mut args, "--manifest")?),
            "--id" => id = Some(next_value(&mut args, "--id")?),
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?} for run-hook\n\n{}",
                    usage()
                )))
            }
        }
    }
    Ok(Command::RunHook {
        manifest: manifest.ok_or_else(|| CliError::Usage("run-hook requires --manifest".into()))?,
        id: id.ok_or_else(|| CliError::Usage("run-hook requires --id".into()))?,
    })
}

fn parse_content_compile_args(args: impl IntoIterator<Item = String>) -> Result<Command, CliError> {
    let mut config = None;
    let mut output = None;
    let mut php_only = false;
    let mut inspect = false;
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--config" => config = Some(next_path(&mut args, "--config")?),
            "--output" => output = Some(next_path(&mut args, "--output")?),
            "--php-only" => php_only = true,
            "--inspect" => inspect = true,
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?} for content-compile\n\n{}",
                    usage()
                )))
            }
        }
    }
    let config =
        config.ok_or_else(|| CliError::Usage("content-compile requires --config".into()))?;
    let output = output.unwrap_or_else(|| PathBuf::from("dist/spacefast-content"));
    Ok(Command::ContentCompile {
        config,
        output,
        php_only,
        inspect,
    })
}

fn no_operands(
    mut args: impl Iterator<Item = String>,
    command: Command,
) -> Result<Command, CliError> {
    if args.next().is_some() {
        return Err(CliError::Usage(usage()));
    }
    Ok(command)
}

fn parse_finalize_args(args: impl IntoIterator<Item = String>) -> Result<FinalizeArgs, CliError> {
    let mut parsed = FinalizeArgs::default();
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--input" => parsed.input = next_path(&mut args, "--input")?,
            "--version-root" => {
                parsed.version_root = Some(next_value(&mut args, "--version-root")?)
            }
            "--output" => parsed.output = Some(next_path(&mut args, "--output")?),
            "--dry-run" => parsed.dry_run = true,
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?} for finalize\n\n{}",
                    usage()
                )))
            }
        }
    }
    if parsed.input.as_os_str().is_empty() {
        return Err(CliError::Usage(format!("missing --input\n\n{}", usage())));
    }
    Ok(parsed)
}

fn next_path(args: &mut impl Iterator<Item = String>, flag: &str) -> Result<PathBuf, CliError> {
    Ok(PathBuf::from(next_value(args, flag)?))
}

fn next_value(args: &mut impl Iterator<Item = String>, flag: &str) -> Result<String, CliError> {
    args.next()
        .ok_or_else(|| CliError::Usage(format!("{flag} requires a value\n\n{}", usage())))
}

fn usage() -> String {
    "usage:
  stattic-runtime finalize --input <input.json> [--version-root <dir>] [--output <file>] [--dry-run]
  stattic-runtime prepare <source.js> <bytecode> [capabilities-json] [generated-source.js]
  stattic-runtime invoke
  stattic-runtime service-broker
  stattic-runtime markdown-to-html
  stattic-runtime content-compile --config <payload.config.ts> [--output <dir>] [--php-only] [--inspect]
  stattic-runtime run-hook --manifest <hooks.json> --id <hook-id>
  stattic-runtime --self-test

finalize takes a `stattic.runtime.finalize.input.v2` payload, runs the
filesystem-rooted site finalize, and reports to --output or stdout."
        .to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The install gate is only as real as this probe: `installer.php` accepts
    /// an engine because the staged binary answered `--self-test`, so the
    /// self-test has to run the finalize for real and leave nothing behind.
    #[test]
    fn self_test_finalizes_a_real_document_and_cleans_up() {
        let root = env::temp_dir().join("stattic-runtime-self-test-unit");
        let _ = fs::remove_dir_all(&root);
        self_test_finalize(&root).expect("self-test finalize");
        let versions = root.join("storage/spaces/s/versions");
        assert!(versions.is_dir(), "the finalize ran under the probe's root");
        assert!(!versions.join("v").exists(), "a dry run publishes nothing");
        let _ = fs::remove_dir_all(&root);

        self_test().expect("self-test");
        assert!(!env::temp_dir()
            .join(format!("stattic-runtime-self-test-{}", std::process::id()))
            .exists());
    }

    #[test]
    fn content_compile_evaluates_typescript_and_keeps_quickjs_fallbacks() {
        let root = env::temp_dir().join(format!(
            "spacefast-content-compile-test-{}",
            std::process::id()
        ));
        let _ = fs::remove_dir_all(&root);
        fs::create_dir_all(&root).expect("create compiler fixture root");
        let config = root.join("payload.config.ts");
        let collections = root.join("collections");
        let output = root.join("compiled");
        fs::create_dir_all(&collections).expect("create collection fixture directory");
        fs::write(
            collections.join("articles.ts"),
            r#"
const collectionSlug: string = "articles";
export const Articles = {
  slug: collectionSlug,
  fields: [
    { name: "title", type: "text", required: true },
    { name: "priority", type: "number", validate: value => value <= 5 || "Too high" },
  ],
  hooks: { beforeChange: [async ({ data }) => data] },
};
"#,
        )
        .expect("write imported TypeScript fixture");
        fs::write(
            &config,
            r#"
import { Articles } from "./collections/articles.ts";
export default {
  collections: [Articles],
  globals: [],
};
"#,
        )
        .expect("write TypeScript fixture");

        content_compile(config, output.clone(), false, false).expect("compile Payload config");
        let schema: serde_json::Value = serde_json::from_slice(
            &fs::read(output.join("schema.json")).expect("read compiled schema"),
        )
        .expect("parse compiled schema");
        let hooks: serde_json::Value = serde_json::from_slice(
            &fs::read(output.join("hooks.json")).expect("read compiled hooks"),
        )
        .expect("parse compiled hooks");
        assert_eq!(schema["schema_version"], 3);
        assert_eq!(schema["collections"][0]["slug"], "articles");
        let hook_list = hooks["hooks"].as_array().expect("hook list");
        assert!(hook_list.iter().any(|hook| hook["target"] == "php"));
        let hook = hook_list
            .iter()
            .find(|hook| hook["target"] == "quick_js")
            .expect("QuickJS fallback hook");
        let executed = payloadwp::javascript::run_executable(
            hook["source"].as_str().expect("hook source"),
            &serde_json::json!({ "data": { "title": "Hello" } }),
            hook["event"].as_str().expect("hook event"),
        )
        .expect("execute QuickJS fallback");
        assert_eq!(executed["title"], "Hello");
        assert!(output.join("payloadwp.php").is_file());
        let _ = fs::remove_dir_all(root);
    }
}
