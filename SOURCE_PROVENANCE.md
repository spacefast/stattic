# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7322c893f0cf3d7c9b5a8c1d990d2928a432464b`
- Runtime source hash: `9fe79371b95f25f9e2ad904d058cb9d71728e087054dd58e7ef96b3334f7aeea`
- Matching release tag: `runtime-7322c893f0cf3d7c9b5a8c1d990d2928a432464b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
