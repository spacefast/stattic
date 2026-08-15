# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1001922079de14a39d97a7edb3ee39063df96625`
- Runtime source hash: `a5856c42be0e4812b52e76b73b47820a0439cd8fd960724cdbde1c8e11028293`
- Matching release tag: `runtime-1001922079de14a39d97a7edb3ee39063df96625`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
