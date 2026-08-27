# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1c521ac51b111b150338342b7e890e8691fc5770`
- Runtime source hash: `b3a83e8d40226e0e34a7d69a68dd4f0fa6125021989932bf8a8a2f93a3328f11`
- Matching release tag: `runtime-1c521ac51b111b150338342b7e890e8691fc5770`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
