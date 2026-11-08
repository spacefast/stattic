# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `11581c31c3d7d7aa8c4273a2a18a4807a6b736a6`
- Runtime source hash: `bf842b0c9a826c27a0ba731189855abf5f9672baa4ff6bda10436a4adfcab9c6`
- Matching release tag: `runtime-11581c31c3d7d7aa8c4273a2a18a4807a6b736a6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
