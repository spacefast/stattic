# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6566db555e21ab78887130fc0e612895337d3220`
- Runtime source hash: `fef2fc7f555fb5eeccf4bfcf5f29d3e88632796cff752ae4cb6580733b90c0f6`
- Matching release tag: `runtime-6566db555e21ab78887130fc0e612895337d3220`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
