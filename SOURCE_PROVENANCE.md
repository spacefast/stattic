# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2f0acd333e4e3d6b4f4e084dd646adb2f3cce0f7`
- Runtime source hash: `5e7f5e70caab15a4be560d8fad21295bd0c8e6179f7d12818568c06dd5834e35`
- Matching release tag: `runtime-2f0acd333e4e3d6b4f4e084dd646adb2f3cce0f7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
