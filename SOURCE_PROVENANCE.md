# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d34fcc3b0feda0b21c4b112dcb762ea2b47e63d1`
- Runtime source hash: `bae4296d3430eef0f91b6ed46ec5a1faa30d336488d4f1621bc32bb303b079d6`
- Matching release tag: `runtime-d34fcc3b0feda0b21c4b112dcb762ea2b47e63d1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
