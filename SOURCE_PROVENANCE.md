# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6f6c5de3d246c674ebf070f3f723bb6710128e74`
- Runtime source hash: `76afd622eaf5bf3de3478a911a9fcab43862aa49a72ce94bb5b6c336a1457482`
- Matching release tag: `runtime-6f6c5de3d246c674ebf070f3f723bb6710128e74`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
