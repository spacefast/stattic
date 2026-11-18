//! `typedpress` — compiles `typedpress.config.ts` into WordPress plugins. The command line lives in `typedpress::cli`.

fn main() -> std::process::ExitCode {
    typedpress::cli::main()
}
