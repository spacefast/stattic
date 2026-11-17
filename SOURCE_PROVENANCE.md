# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2eab2c5f574763437106dcb08bef2493d952de96`
- Runtime source hash: `eff1ef954a33df57a356d384ee5f118753f275cb2894bce8903bbbed907dca38`
- Matching release tag: `runtime-2eab2c5f574763437106dcb08bef2493d952de96`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
