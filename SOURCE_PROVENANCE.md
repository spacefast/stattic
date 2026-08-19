# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ae0849675b3fff827aff2fa76c696776501581c9`
- Runtime source hash: `61390305b08e88919822eaad26dc726017809bc551fd4dc18d86e2efc5d251a2`
- Matching release tag: `runtime-ae0849675b3fff827aff2fa76c696776501581c9`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
