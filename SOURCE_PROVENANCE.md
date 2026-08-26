# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ed6f95d4f13dfa14bab9df4da46aeb7b190da03e`
- Runtime source hash: `07744ca4c29e15c23b1606d20c0bb7fb3c67f11fe8660570b766ae18214ad73b`
- Matching release tag: `runtime-ed6f95d4f13dfa14bab9df4da46aeb7b190da03e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
