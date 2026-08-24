# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d95f312dd5ec7323b7ebd6e55cb7ebb30b33e2c1`
- Runtime source hash: `89b2529b9aa07c1ccc9cc758f107b227e48bd3e2911f0b3a7ed03df579bd4379`
- Matching release tag: `runtime-d95f312dd5ec7323b7ebd6e55cb7ebb30b33e2c1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
