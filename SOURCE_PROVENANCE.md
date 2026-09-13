# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e097141f487e6184441fc3d87aafe1b8aa5075ea`
- Runtime source hash: `86704ce83296b8b41097ad281d705bfc897e9adccd27c69fd73a05ed6dc530e3`
- Matching release tag: `runtime-e097141f487e6184441fc3d87aafe1b8aa5075ea`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
