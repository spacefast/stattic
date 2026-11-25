//! `spacepress` — compiles `spacepress.config.ts` into WordPress plugins. The command line lives in `spacepress::cli`.

fn main() -> std::process::ExitCode {
    spacepress::cli::main()
}
