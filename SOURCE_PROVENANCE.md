# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `732c3c6f7ce19663c3135189d6667f6587f7b410`
- Runtime source hash: `8ce6fa36ecc3139467eba057213ecc4aa97f59c7eb19e3197f83cd799c1cec1d`
- Matching release tag: `runtime-732c3c6f7ce19663c3135189d6667f6587f7b410`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
