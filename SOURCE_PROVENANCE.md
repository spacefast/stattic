# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6b7928c9280db9c8de83fd2be4ac5e71189c58e9`
- Runtime source hash: `75367e452474b6e565c5e82c2cc4d4a303bd26c191c4ce1e6cd3492b2d17c336`
- Matching release tag: `runtime-6b7928c9280db9c8de83fd2be4ac5e71189c58e9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
