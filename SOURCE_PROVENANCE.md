# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a838eb65c51647a93c657d02e93fc60185ded8c9`
- Runtime source hash: `fa0351604b9f8d98f836738a8f0e939089aa6bfadc70f27f4f0843aae7ae06af`
- Matching release tag: `runtime-a838eb65c51647a93c657d02e93fc60185ded8c9`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
