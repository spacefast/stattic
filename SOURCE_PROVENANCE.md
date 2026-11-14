# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9765ed827025965fef511254b5c5ea8f0582dafa`
- Runtime source hash: `6bf59d1b2a78947d29d2ab437d3f05e6d56a3306b6900fe0e536f42d771804ee`
- Matching release tag: `runtime-9765ed827025965fef511254b5c5ea8f0582dafa`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
