# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fe4b68187a84b8ea3eff27cc05fa1ed8091e7bf1`
- Runtime source hash: `d5a50a9f1479a856db1786951dc9328bbd0e36a43e14f13410d8b2595c642ca7`
- Matching release tag: `runtime-fe4b68187a84b8ea3eff27cc05fa1ed8091e7bf1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
