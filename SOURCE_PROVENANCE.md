# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e2c3878eaccc236d0a92c35c764fc13585b200cf`
- Runtime source hash: `81d13a9dfdf0d8bb3f7f6be46e7b2a949ceac174024bd03e0583117cec36175f`
- Matching release tag: `runtime-e2c3878eaccc236d0a92c35c764fc13585b200cf`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
