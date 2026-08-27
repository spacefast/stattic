# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `483d887b8b10af62d5d4f31a7a426f6e9e3df94e`
- Runtime source hash: `cf2e8d911aa93aa5d2399baa49ee3840fb68efd2a0458ead34b1eaac08e4c6c8`
- Matching release tag: `runtime-483d887b8b10af62d5d4f31a7a426f6e9e3df94e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
