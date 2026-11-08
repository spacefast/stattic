# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7e6f898aff149785292ef75b59af9177e39b6ce5`
- Runtime source hash: `405864f96bb2a0c4f70c2cf1029f75ce2c6a3f306734707b41b4d3a154d7bda7`
- Matching release tag: `runtime-7e6f898aff149785292ef75b59af9177e39b6ce5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
