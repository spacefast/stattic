# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d5406ca97ee5c76d4bf8f5bba2eac00acc06b0b3`
- Runtime source hash: `5d79be8429d4fff0639933b82321ca3f1b226a4d598c64a4ff83751764d2550e`
- Matching release tag: `runtime-d5406ca97ee5c76d4bf8f5bba2eac00acc06b0b3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
