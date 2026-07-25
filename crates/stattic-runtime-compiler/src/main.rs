use stattic_runtime_core::{
    compile_build, compile_finalize, finalize_site, finalize_site_dry_run, read_build_input,
    read_finalize_input, read_import_rebind_input, read_input_format, read_site_finalize_input,
    rebind_imported_version, write_import_rebind_output, write_output, write_site_finalize_error,
    write_site_finalize_output, FinalizeError, RuntimeCompileError, RuntimeCompileOutput,
    SITE_FINALIZE_INPUT_FORMAT,
};
use std::env;
use std::path::PathBuf;

#[derive(Debug)]
enum Command {
    Build(CompileArgs),
    Finalize(CompileArgs),
    RebindImport { input: PathBuf, output: PathBuf },
    SelfTest,
}

#[derive(Debug, Default)]
struct CompileArgs {
    input: PathBuf,
    source_root: Option<String>,
    version_root: Option<String>,
    previous_pack: Option<String>,
    out_dir: Option<PathBuf>,
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
        eprintln!("stattic-runtime-compiler: {error}");
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
            emit_output(compile_build(input), args.out_dir, args.dry_run)?;
        }
        Command::Finalize(args) => {
            if read_input_format(&args.input)? == SITE_FINALIZE_INPUT_FORMAT {
                finalize_v2(args)?;
            } else {
                // `read_finalize_input` rejects anything but the v1 format.
                if args.output.is_some() {
                    return Err(CliError::Usage(
                        "--output requires a v2 finalize input; v1 uses --out-dir".to_string(),
                    ));
                }
                let mut input = read_finalize_input(&args.input)?;
                if let Some(version_root) = args.version_root {
                    input.version_root = version_root;
                }
                if args.previous_pack.is_some() {
                    input.previous_pack = args.previous_pack;
                }
                emit_output(compile_finalize(input), args.out_dir, args.dry_run)?;
            }
        }
        Command::RebindImport { input, output } => rebind_import(&input, &output)?,
        Command::SelfTest => self_test()?,
    }
    Ok(())
}

/// Rebinds an imported version's Zero executable graph to its target space.
/// Failures write the stable v2 error envelope to `--output` (the same shape
/// the PHP native adapter reads for finalize) before exiting nonzero.
fn rebind_import(input: &std::path::Path, output: &std::path::Path) -> Result<(), CliError> {
    let result = (|| -> Result<(), FinalizeError> {
        let rebound = rebind_imported_version(read_import_rebind_input(input)?)?;
        write_import_rebind_output(&rebound, output)
    })();
    if let Err(error) = result {
        write_site_finalize_error(&error, output)?;
        return Err(error.into());
    }
    Ok(())
}

/// Runs the v2 filesystem-rooted site finalize. `--dry-run` executes the
/// complete pipeline but discards the stage instead of publishing the
/// immutable version; the output JSON goes to `--output` (with any open
/// manifest spilled to a side-car) or stdout.
fn finalize_v2(args: CompileArgs) -> Result<(), CliError> {
    if args.out_dir.is_some() {
        return Err(CliError::Usage(
            "--out-dir applies to v1 inputs; v2 finalize writes into the version root and reports via --output".to_string(),
        ));
    }
    let output_path = args.output.clone();
    let result = (|| -> Result<(), FinalizeError> {
        let mut input = read_site_finalize_input(&args.input)?;
        if let Some(version_root) = args.version_root {
            input.version_root = version_root;
        }
        if args.previous_pack.is_some() {
            input.previous_pack = args.previous_pack;
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
    println!(
        "{{\"format\":\"stattic.runtime.compiler.self-test.v1\",\"version\":\"{}\"}}",
        env!("CARGO_PKG_VERSION")
    );
    Ok(())
}

fn emit_output(
    output: RuntimeCompileOutput,
    out_dir: Option<PathBuf>,
    dry_run: bool,
) -> Result<(), CliError> {
    let print_stdout = dry_run || out_dir.is_none();
    if let Some(out_dir) = out_dir {
        write_output(out_dir, &output)?;
    }
    if print_stdout {
        println!("{}", serde_json::to_string_pretty(&output)?);
    }
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
    if command == "--rebind-import" {
        return parse_rebind_import_args(args);
    }
    let compile_args = parse_compile_args(args)?;
    match command.as_str() {
        "build" => Ok(Command::Build(compile_args)),
        "finalize" => Ok(Command::Finalize(compile_args)),
        "--help" | "-h" | "help" => Err(CliError::Usage(usage())),
        _ => Err(CliError::Usage(format!(
            "unknown command {command:?}\n\n{}",
            usage()
        ))),
    }
}

/// The rebind lane accepts exactly `--input <file> --output <file>`. The
/// compile-lane flags (`--out-dir`, `--source-root`, `--version-root`,
/// `--previous-pack`, `--dry-run`) are rejected as unknown arguments.
fn parse_rebind_import_args(args: impl IntoIterator<Item = String>) -> Result<Command, CliError> {
    let mut input: Option<PathBuf> = None;
    let mut output: Option<PathBuf> = None;
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--input" => input = Some(next_path(&mut args, "--input")?),
            "--output" => output = Some(next_path(&mut args, "--output")?),
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?} for --rebind-import\n\n{}",
                    usage()
                )))
            }
        }
    }
    match (input, output) {
        (Some(input), Some(output)) => Ok(Command::RebindImport { input, output }),
        _ => Err(CliError::Usage(format!(
            "--rebind-import requires --input and --output\n\n{}",
            usage()
        ))),
    }
}

fn parse_compile_args(args: impl IntoIterator<Item = String>) -> Result<CompileArgs, CliError> {
    let mut parsed = CompileArgs::default();
    let mut args = args.into_iter();
    while let Some(arg) = args.next() {
        match arg.as_str() {
            "--input" => parsed.input = next_path(&mut args, "--input")?,
            "--source-root" => parsed.source_root = Some(next_value(&mut args, "--source-root")?),
            "--version-root" => {
                parsed.version_root = Some(next_value(&mut args, "--version-root")?)
            }
            "--previous-pack" => {
                parsed.previous_pack = Some(next_value(&mut args, "--previous-pack")?)
            }
            "--out-dir" => parsed.out_dir = Some(next_path(&mut args, "--out-dir")?),
            "--output" => parsed.output = Some(next_path(&mut args, "--output")?),
            "--dry-run" => parsed.dry_run = true,
            "--help" | "-h" => return Err(CliError::Usage(usage())),
            _ => {
                return Err(CliError::Usage(format!(
                    "unknown argument {arg:?}\n\n{}",
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
  stattic-runtime-compiler build --input <input.json> [--source-root <dir>] [--out-dir <dir>] [--dry-run]
  stattic-runtime-compiler finalize --input <input.json> [--version-root <dir>] [--previous-pack <pack>] [--out-dir <dir>] [--output <file>] [--dry-run]
  stattic-runtime-compiler --rebind-import --input <input.json> --output <output.json>
  stattic-runtime-compiler --self-test

finalize dispatches on the input file's format: v1 inputs
(stattic.runtime.finalize.input.v1) compile artifacts into --out-dir; v2
inputs (stattic.runtime.finalize.input.v2) run the filesystem-rooted site
finalize and report to --output or stdout. --rebind-import rebinds an
imported version's Zero executable graph to its target space
(spacefast.finalizer.import-rebind.input.v1)."
        .to_string()
}
