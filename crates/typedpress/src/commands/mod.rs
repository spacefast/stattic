pub(crate) mod abilities;
pub(crate) mod add;
pub(crate) mod build;
pub(crate) mod deploy;
mod describe;
mod dev;
mod doctor;
pub(crate) mod export;
pub(crate) mod init;
mod init_github;
pub(crate) mod links;
pub(crate) mod login;
pub(crate) mod mcp;
pub(crate) mod plugin;
pub(crate) mod pull;
pub(crate) mod push;
pub(crate) mod remote;
pub(crate) mod site;
pub(crate) mod styles;
mod sync;

pub(crate) use sync::run as sync;

pub(crate) fn run(args: &crate::cli::Args) -> std::process::ExitCode {
    match args.command.as_str() {
        "doctor" => doctor::run(args),
        "dev" => dev::run(args),
        _ => build::run(args),
    }
}
