# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5feefc5f70b7f49e2e347a5841066d6dd07dbf2b`
- Runtime source hash: `dfafaac299d59b00fc544bedf09b7f3d234198e82e7087acecd1970e8e81b80c`
- Matching release tag: `runtime-5feefc5f70b7f49e2e347a5841066d6dd07dbf2b`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
