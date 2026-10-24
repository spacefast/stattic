# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6b6734ae5c3f6fb0c9e0987d0f573aeb66fd559e`
- Runtime source hash: `904e91135a9e2ddf1060e04a925dea0659b93b327a90d6651a51a2764f91b6a5`
- Matching release tag: `runtime-6b6734ae5c3f6fb0c9e0987d0f573aeb66fd559e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
