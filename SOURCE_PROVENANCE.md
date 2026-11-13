# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9c83d69a357d399ce537ca110e9d66c617302e5d`
- Runtime source hash: `e49ddfdfb6f9f87dec661cf43c1e67e4250be5ac8b691a4be41c7bad8cf1e1ee`
- Matching release tag: `runtime-9c83d69a357d399ce537ca110e9d66c617302e5d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
