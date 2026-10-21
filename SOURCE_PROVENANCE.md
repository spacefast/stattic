# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `935a193e1b8252501af953fb81fdc44b706d2722`
- Runtime source hash: `53fc8253c537a7891ab69cb7fafd7f91a4a0a96476bc900a6eb5cc8ed27b1cc4`
- Matching release tag: `runtime-935a193e1b8252501af953fb81fdc44b706d2722`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
