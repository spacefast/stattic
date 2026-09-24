# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `512e80b2bb75bde92af76d806940e58a75f3e25f`
- Runtime source hash: `7723d93b278463c22b2868833486181fc511b0f33dc2526921fbe6ccded1df6b`
- Matching release tag: `runtime-512e80b2bb75bde92af76d806940e58a75f3e25f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
