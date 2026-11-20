# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `63c734521a4f9cc25dc8f2d3e93a2755ab24b934`
- Runtime source hash: `9aa8ea92c70c17241df34d8ebbe2173a0ca2301b7cc714d157ba564d7dda2ae1`
- Matching release tag: `runtime-63c734521a4f9cc25dc8f2d3e93a2755ab24b934`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
