# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8c65219b365062b704797eb56b3324332e9cc560`
- Runtime source hash: `c0750c6b57a72e5241434a07da2b8dd4371dc950faa3551074d35997fd87b264`
- Matching release tag: `runtime-8c65219b365062b704797eb56b3324332e9cc560`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
