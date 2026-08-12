use stattic_runtime_core::{
    compile_build, finalize_site, finalize_site_dry_run, read_build_input,
    read_site_finalize_input, transform_private_root, write_site_finalize_error,
    write_site_finalize_output, FinalizeError, RuntimeCompileError,
};
use std::env;
use std::path::PathBuf;

#[derive(Debug)]
enum Command {
    Build(CompileArgs),
    Finalize(CompileArgs),
    Prepare(Vec<String>),
    Migrate(Option<String>),
    CatalogTransform(PathBuf),
    Invoke,
    DbBroker,
    ServiceBroker,
    SelfTest,
}

#[derive(Clone, Copy)]
enum CompileCommand {
    Build,
    Finalize,
}

impl CompileCommand {
    fn name(self) -> &'static str {
        match self {
            Self::Build => "build",
            Self::Finalize => "finalize",
        }
    }
}

#[derive(Debug, Default)]
struct CompileArgs {
    input: PathBuf,
    source_root: Option<String>,
    version_root: Option<String>,
    output: Option<PathBuf>,
    dry_run: bool,
}

#[derive(Debug)]
enum CliError {
    Usage(String),
    Compile(RuntimeCompileError),
    Finalize(FinalizeError),
    Json(serde_json::Error),
}

impl std::fmt::Display for CliError {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Self::Usage(message) => f.write_str(message),
            Self::Compile(error) => write!(f, "{error}"),
            Self::Finalize(error) => write!(f, "{error}"),
            Self::Json(error) => write!(f, "{error}"),
        }
    }
}

impl std::error::Error for CliError {}

impl From<RuntimeCompileError> for CliError {
    fn from(error: RuntimeCompileError) -> Self {
        Self::Compile(error)
    }
}

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
        Command::Build(args) => {
            let mut input = read_build_input(&args.input)?;
            if let Some(source_root) = args.source_root {
                input.source_root = source_root;
            }
            println!("{}", serde_json::to_string_pretty(&compile_build(input))?);
        }
        Command::Finalize(args) => finalize_site_command(args)?,
        Command::Prepare(args) => exit_with_status(stattic_zero_runner::prepare(&args)),
        Command::Migrate(version_root) => {
            exit_with_status(stattic_zero_runner::migrate(version_root.as_deref()))
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
fn finalize_site_command(args: CompileArgs) -> Result<(), CliError> {
    let output_path = args.output.clone();
    let result = (|| -> Result<(), FinalizeError> {
        let mut input = read_site_finalize_input(&args.input)?;
        if let Some(version_root) = args.version_root {
            input.version_root = version_root;
        }
        let output = if args.dry_run {
            finalize_site_dry_run(input)?
        } else {
            finalize_site(input)?
        };
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

/// Proves the binary can execute a compile end to end before printing the
/// probe line installers and image builds assert on.
fn self_test() -> Result<(), CliError> {
    let output = compile_build(serde_json::from_value(serde_json::json!({
        "format": "stattic.runtime.build.input.v1",
        "sourceRoot": "/tmp/self-test",
        "versionId": "ver_self_test",
        "files": [{ "path": "index.html", "size": 1, "sha256": "00", "contentType": "text/html" }]
    }))?);
    if output.diagnostics.iter().any(|diagnostic| {
        matches!(
            diagnostic.severity,
            stattic_runtime_core::RuntimeDiagnosticSeverity::Error
        )
    }) {
        return Err(CliError::Usage("self-test compile failed".to_string()));
    }
    stattic_zero_runner::self_test().map_err(CliError::Usage)?;
    println!(
        "{{\"format\":\"stattic.runtime.self-test.v1\",\"version\":\"{}\",\"abi\":\"{}\"}}",
        env!("CARGO_PKG_VERSION"),
        stattic_zero_runner::RUNNER_ABI
    );
    Ok(())
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
        "build" => Ok(Command::Build(parse_compile_args(
            args,
            CompileCommand::Build,
        )?)),
        "finalize" => Ok(Command::Finalize(parse_compile_args(
            args,
            CompileCommand::Finalize,
        )?)),
        "prepare" => Ok(Command::Prepare(args.collect())),
        "migrate" => {
            let values = args.collect::<Vec<_>>();
            match values.as_slice() {
                [] => Ok(Command::Migrate(None)),
                [version_root] => Ok(Command::Migrate(Some(version_root.clone()))),
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
        "invoke" => {
            if args.next().is_some() {
                return Err(CliError::Usage(usage()));
            }
            Ok(Command::Invoke)
        }
        "db-broker" => {
            if args.next().is_some() {
                return Err(CliError::Usage(usage()));
            }
            Ok(Command::DbBroker)
        }
        "service-broker" => {
            if args.next().is_some() {
                return Err(CliError::Usage(usage()));
            }
            Ok(Command::ServiceBroker)
        }
        "--help" | "-h" | "help" => Err(CliError::Usage(usage())),
        _ => Err(CliError::Usage(format!(
            "unknown command {command:?}\n\n{}",
            usage()
        ))),
    }
}

fn parse_compile_args(
    args: impl IntoIterator<Item = String>,
    command: CompileCommand,
) -> Result<CompileArgs, CliError> {
    let mut parsed = CompileArgs::default();
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--input" => parsed.input = next_path(&mut args, "--input")?,
            "--source-root" if matches!(command, CompileCommand::Build) => {
                parsed.source_root = Some(next_value(&mut args, "--source-root")?)
            }
            "--version-root" if matches!(command, CompileCommand::Finalize) => {
                parsed.version_root = Some(next_value(&mut args, "--version-root")?)
            }
            "--output" if matches!(command, CompileCommand::Finalize) => {
                parsed.output = Some(next_path(&mut args, "--output")?)
            }
            "--dry-run" if matches!(command, CompileCommand::Finalize) => parsed.dry_run = true,
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?} for {}\n\n{}",
                    command.name(),
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
  stattic-runtime build --input <input.json> [--source-root <dir>]
  stattic-runtime finalize --input <input.json> [--version-root <dir>] [--output <file>] [--dry-run]
  stattic-runtime prepare <source.js> <bytecode> [capabilities-json] [generated-source.js]
  stattic-runtime migrate <version-root>
  stattic-runtime catalog-transform <private-root>
  stattic-runtime invoke
  stattic-runtime db-broker
  stattic-runtime service-broker
  stattic-runtime --self-test

build compiles a `stattic.runtime.build.input.v1` payload and reports to
stdout. finalize takes a `stattic.runtime.finalize.input.v2` payload, runs
the filesystem-rooted site finalize, and reports to --output or stdout."
        .to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn build_rejects_finalize_only_output_controls() {
        for (args, flag) in [
            (
                vec!["--input", "input.json", "--output", "output.json"],
                "--output",
            ),
            (vec!["--input", "input.json", "--dry-run"], "--dry-run"),
        ] {
            let error =
                parse_compile_args(args.into_iter().map(str::to_string), CompileCommand::Build)
                    .expect_err("finalize-only arguments are rejected");
            assert!(
                error
                    .to_string()
                    .starts_with(&format!("unknown argument {flag:?} for build")),
                "{error}"
            );
        }
    }
}
