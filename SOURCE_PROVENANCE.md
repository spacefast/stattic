# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `056aabf134ad22f52034ea0249c81d27038e350a`
- Runtime source hash: `0f32f15f4dc7744af90582936dcd8b24adefa2e33130d2863fc4f6780b5c670c`
- Matching release tag: `runtime-056aabf134ad22f52034ea0249c81d27038e350a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
