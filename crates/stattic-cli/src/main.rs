use stattic_runtime_core::{build_bundle, inspect_bundle, BuildBundleInput};
use std::env;
use std::path::PathBuf;

fn main() {
    if let Err(error) = run() {
        eprintln!("stattic: {error}");
        std::process::exit(2);
    }
}

fn run() -> Result<(), Box<dyn std::error::Error>> {
    let mut arguments = env::args().skip(1);
    match arguments.next().as_deref() {
        Some("build") => build(arguments.collect()),
        Some("inspect") => inspect(arguments.collect()),
        Some("version") | Some("--version") | Some("-V") => {
            println!("stattic {}", env!("CARGO_PKG_VERSION"));
            Ok(())
        }
        Some("help") | Some("--help") | Some("-h") | None => {
            print_help();
            Ok(())
        }
        Some(command) => Err(format!("unknown command {command:?}\n\n{}", help()).into()),
    }
}

fn build(arguments: Vec<String>) -> Result<(), Box<dyn std::error::Error>> {
    let mut source = None;
    let mut output = None;
    let mut space_id = "local".to_string();
    let mut version_id = "v1".to_string();
    let mut index = 0;
    while index < arguments.len() {
        match arguments[index].as_str() {
            "--output" | "-o" => {
                index += 1;
                output = arguments.get(index).map(PathBuf::from);
            }
            "--space-id" => {
                index += 1;
                space_id = required_value(&arguments, index, "--space-id")?.to_string();
            }
            "--version-id" => {
                index += 1;
                version_id = required_value(&arguments, index, "--version-id")?.to_string();
            }
            value if value.starts_with('-') => {
                return Err(format!("unknown build option {value:?}").into());
            }
            value if source.is_none() => source = Some(PathBuf::from(value)),
            value => return Err(format!("unexpected build argument {value:?}").into()),
        }
        index += 1;
    }
    let source_root = source.unwrap_or_else(|| PathBuf::from("."));
    let output_root = output.ok_or("stattic build requires --output <directory>")?;
    let descriptor = build_bundle(BuildBundleInput {
        source_root,
        output_root: output_root.clone(),
        space_id,
        version_id,
    })?;
    println!(
        "Built {} ({})\n{}",
        output_root.display(),
        descriptor.profile,
        descriptor.content_digest
    );
    Ok(())
}

fn inspect(arguments: Vec<String>) -> Result<(), Box<dyn std::error::Error>> {
    if arguments.len() != 1 {
        return Err("usage: stattic inspect <bundle-directory>".into());
    }
    let descriptor = inspect_bundle(&arguments[0])?;
    println!("{}", serde_json::to_string_pretty(&descriptor)?);
    Ok(())
}

fn required_value<'a>(
    arguments: &'a [String],
    index: usize,
    option: &str,
) -> Result<&'a str, Box<dyn std::error::Error>> {
    arguments
        .get(index)
        .map(String::as_str)
        .ok_or_else(|| format!("{option} requires a value").into())
}

fn print_help() {
    println!("{}", help());
}

fn help() -> &'static str {
    "Stattic Builder

Usage:
  stattic build [source] --output <bundle-directory> [--space-id <id>] [--version-id <id>]
  stattic inspect <bundle-directory>
  stattic version

The output directory must be outside the source directory. A portable-static
bundle is fully compiled and does not require Rust or a build step at serving time."
}
