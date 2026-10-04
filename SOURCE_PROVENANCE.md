# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `58793b1fefa2a2b116e24eeda9d9e71fed264f3b`
- Runtime source hash: `47e2f2342bdec1cc181015f2eb50be30161ba2a7f322be948e88ed9eaf3b51db`
- Matching release tag: `runtime-58793b1fefa2a2b116e24eeda9d9e71fed264f3b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
