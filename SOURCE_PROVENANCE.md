# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c381e1a2c02335c17438200a19016f1d42f3be72`
- Runtime source hash: `24b32437fa7a400ee0846fbe6c56015bca1289914486932d0bc6173d6edb2f78`
- Matching release tag: `runtime-c381e1a2c02335c17438200a19016f1d42f3be72`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
