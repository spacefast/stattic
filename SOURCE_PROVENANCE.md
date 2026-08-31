# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `396065a139dd2ddfb7b22edbdc1208b375ce1758`
- Runtime source hash: `732fa9a97e10e71edb1abb8f40fe12bd8685417f3e9fee79fb9c26d06cb45320`
- Matching release tag: `runtime-396065a139dd2ddfb7b22edbdc1208b375ce1758`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
