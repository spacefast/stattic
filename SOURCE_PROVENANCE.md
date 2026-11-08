# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6850e73434a665d4b76913bbf6ca3e3eb04907ac`
- Runtime source hash: `42f45ca828cbdb905b359d8c015be9879e740892dd112d8827c8dec0dc0032b5`
- Matching release tag: `runtime-6850e73434a665d4b76913bbf6ca3e3eb04907ac`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
