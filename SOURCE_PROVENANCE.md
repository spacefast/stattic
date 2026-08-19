# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `66f4e74377ef1973a6dfede494fc26144f959698`
- Runtime source hash: `fe1d82c8f2159549eb917ed09afb6d1c99554bff4fe5f9be5f424f9f2b50c8a9`
- Matching release tag: `runtime-66f4e74377ef1973a6dfede494fc26144f959698`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
