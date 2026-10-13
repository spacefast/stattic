# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fa4f24b2afe792f7869183708c1c18c95d3a5a8a`
- Runtime source hash: `1643a6fb07ebb7751dae55f6cced936d67d3f4fecb9b129ae4ed0c9db0306a3f`
- Matching release tag: `runtime-fa4f24b2afe792f7869183708c1c18c95d3a5a8a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
