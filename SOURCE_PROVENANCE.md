# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `456b59b06c6a7ac5c1d3b0f847425cb5eacb39ed`
- Runtime source hash: `c7e89598072c74dab686cf579c42ab7d616591c5ea50ec06f0755befe0caa717`
- Matching release tag: `runtime-456b59b06c6a7ac5c1d3b0f847425cb5eacb39ed`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
