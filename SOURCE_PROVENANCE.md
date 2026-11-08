# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ee0f4d11551ebb1b2085932c6e18cf5f1ace9b16`
- Runtime source hash: `cdc6c7aa2e56d679b0fcc8a6845dff2a3b51c9597481c022e7a083914811fcd0`
- Matching release tag: `runtime-ee0f4d11551ebb1b2085932c6e18cf5f1ace9b16`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
