# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `45fab612aca79f2e1720a72719698f44969e21ce`
- Runtime source hash: `fc212c22ba9091aa4ffe0054760ae8c946a95573084d88edc44c513a26a9dabe`
- Matching release tag: `runtime-45fab612aca79f2e1720a72719698f44969e21ce`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
