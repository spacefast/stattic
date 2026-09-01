# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9ad12ca236884898e6e111d41074a089cc1db2ab`
- Runtime source hash: `44f0a2097d906af463a6ce2c6220603b88329ac8626cd1ed000e49f47824d799`
- Matching release tag: `runtime-9ad12ca236884898e6e111d41074a089cc1db2ab`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
