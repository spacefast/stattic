# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fc630e28a957bf18950307e13d3e92a4ce444270`
- Runtime source hash: `8e2ab370702292c46e18845fbddb4ebbec505abee76a02d77e79ccd71ae6b77c`
- Matching release tag: `runtime-fc630e28a957bf18950307e13d3e92a4ce444270`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
