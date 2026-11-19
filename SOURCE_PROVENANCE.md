# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `92a935651c361e0d21993e543a44fb319b30979b`
- Runtime source hash: `1599e6c63f331e652bec4a024c1c368f99849ad385636d3ba1ba160de12209ca`
- Matching release tag: `runtime-92a935651c361e0d21993e543a44fb319b30979b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
