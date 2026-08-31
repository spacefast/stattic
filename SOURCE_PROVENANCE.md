# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4ebdf4ed5afc4c78ad5c63c88d49656c9745d0e5`
- Runtime source hash: `dde0fdd8c0c9b75620d2c9c42bd12d33bc3170883f48f3c84008b9b862fb1fc3`
- Matching release tag: `runtime-4ebdf4ed5afc4c78ad5c63c88d49656c9745d0e5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
