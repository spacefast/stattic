# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2b1e8cc3d461e43d6d297929c346ee7a18f0278b`
- Runtime source hash: `bd6b46858c9374183f0cf2630019422ad81c8f04503f7c5af7c3cf6896b022ee`
- Matching release tag: `runtime-2b1e8cc3d461e43d6d297929c346ee7a18f0278b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
