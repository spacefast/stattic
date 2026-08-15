# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `919a263241508312c1e409b0665300813a322fb2`
- Runtime source hash: `8fd0792e2ec15a946c08f0224f9eb19dc7738100a63c60c8a7cd1e56bf2e245a`
- Matching release tag: `runtime-919a263241508312c1e409b0665300813a322fb2`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
