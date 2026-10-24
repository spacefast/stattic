# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `661467777fa2c7b6ba4a4d2d1ee9cd9de42e8211`
- Runtime source hash: `431b7f139a2493544bbd03d088d7fbac8a82b37f389926cd5cf501bd6e2a0e04`
- Matching release tag: `runtime-661467777fa2c7b6ba4a4d2d1ee9cd9de42e8211`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
