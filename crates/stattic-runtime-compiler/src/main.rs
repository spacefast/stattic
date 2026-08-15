use sha2::{Digest, Sha256};
use stattic_runtime_core::{
    finalize_site, read_site_finalize_input, transform_private_root, write_site_finalize_error,
    write_site_finalize_output, FinalizeError, RuntimeDiagnosticSeverity, SiteFinalizeInput,
    SITE_FINALIZE_INPUT_FORMAT,
};
use std::env;
use std::fs;
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
    Migrate(String),
    CatalogTransform(PathBuf),
    Invoke,
    DbBroker,
    ServiceBroker,
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
    SelfTest(String),
}

impl std::fmt::Display for CliError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Usage(message) => f.write_str(message),
            Self::Finalize(error) => write!(f, "{error}"),
            Self::Json(error) => write!(f, "{error}"),
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
        Command::Migrate(version_root) => {
            exit_with_status(stattic_zero_runner::migrate(&version_root))
        }
        Command::CatalogTransform(private_root) => catalog_transform_command(&private_root)?,
        Command::Invoke => stattic_zero_runner::run_stdio(),
        Command::DbBroker => stattic_zero_runner::run_db_broker_stdio(),
        Command::ServiceBroker => stattic_zero_runner::run_service_broker_stdio(),
        Command::SelfTest => self_test()?,
    }
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

/// The one-time `metadata.json → catalog` migration for versions finalized
/// before the catalog existed. Sweeps every retained version under the private
/// storage root, prints the coverage report the release gate reads, and exits
/// nonzero when any version could not be projected — a catalog-less version
/// must stop the cutover, never reach a runtime that cannot answer for it.
fn catalog_transform_command(private_root: &std::path::Path) -> Result<(), CliError> {
    let report = transform_private_root(private_root)?;
    println!("{}", serde_json::to_string_pretty(&report)?);
    if report.failed > 0 {
        std::process::exit(1);
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
        "migrate" => {
            let values = args.collect::<Vec<_>>();
            match values.as_slice() {
                [version_root] => Ok(Command::Migrate(version_root.clone())),
                _ => Err(CliError::Usage(usage())),
            }
        }
        "catalog-transform" => {
            let values = args.collect::<Vec<_>>();
            match values.as_slice() {
                [private_root] => Ok(Command::CatalogTransform(PathBuf::from(private_root))),
                _ => Err(CliError::Usage(usage())),
            }
        }
        "invoke" => no_operands(args, Command::Invoke),
        "db-broker" => no_operands(args, Command::DbBroker),
        "service-broker" => no_operands(args, Command::ServiceBroker),
        "--help" | "-h" | "help" => Err(CliError::Usage(usage())),
        _ => Err(CliError::Usage(format!(
            "unknown command {command:?}\n\n{}",
            usage()
        ))),
    }
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
  stattic-runtime migrate <version-root>
  stattic-runtime catalog-transform <private-root>
  stattic-runtime invoke
  stattic-runtime db-broker
  stattic-runtime service-broker
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
}
