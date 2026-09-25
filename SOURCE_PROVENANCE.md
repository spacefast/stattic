# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `acf9823d9a6bd1e86dcb1e4ed6df0e06d10146c4`
- Runtime source hash: `e967a58295cf995ddec5aaa702503740b0c9c6f3124145dd3e884652f53b2b14`
- Matching release tag: `runtime-acf9823d9a6bd1e86dcb1e4ed6df0e06d10146c4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
