# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `560e8a63c3cfef69b56dc3a48247c351d7fc0c6a`
- Runtime source hash: `3c8f68030341abc0b04bc969ba29cf1c69951bf9aab161c813920f9b68dce131`
- Matching release tag: `runtime-560e8a63c3cfef69b56dc3a48247c351d7fc0c6a`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
