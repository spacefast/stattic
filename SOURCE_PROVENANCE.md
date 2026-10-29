# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `87b0e340c3276a1488fa5fd4262a2e41fd3ced8c`
- Runtime source hash: `0ac203c249f0ee5ab8a0ff1f4ff81e8a47ce9bccff7feeb1b77d500b56d34e65`
- Matching release tag: `runtime-87b0e340c3276a1488fa5fd4262a2e41fd3ced8c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
