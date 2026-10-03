# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2acf920b2e4781f907a2033dbc114870bac0f977`
- Runtime source hash: `823a7bb704dbdb99a16bf4e90463059d2a08bcd653e2ebbc80448ec428193f91`
- Matching release tag: `runtime-2acf920b2e4781f907a2033dbc114870bac0f977`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
