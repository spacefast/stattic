# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `45bcf194e5f120ae9e73c7a2ea3301037d198ad1`
- Runtime source hash: `e564b61f6854dfc3be5d1a825045e55f0006531cf6b2c08e901fe0778688ebdf`
- Matching release tag: `runtime-45bcf194e5f120ae9e73c7a2ea3301037d198ad1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
