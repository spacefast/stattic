# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `91f698aa4e5208d97c20d52c7bb5086f5fd6d56f`
- Runtime source hash: `fec8ff8b9ab7c93827fea1ccff86c4c59260377f9b9b395387814ab7f77984ed`
- Matching release tag: `runtime-91f698aa4e5208d97c20d52c7bb5086f5fd6d56f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
