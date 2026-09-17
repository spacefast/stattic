# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0be2d269a10e6ffe0814d2f2b1cbbe698c78dde4`
- Runtime source hash: `71233ea7b27a31cf61fa0a4336b9d2ca8a39cd5d1aa0903afdef1252beb239e8`
- Matching release tag: `runtime-0be2d269a10e6ffe0814d2f2b1cbbe698c78dde4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
