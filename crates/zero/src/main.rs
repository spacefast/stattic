//! `zero` — compiles `zero.config.ts` into WordPress plugins. The command line lives in `zero::cli`.

fn main() -> std::process::ExitCode {
    zero::cli::main()
}
