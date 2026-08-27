# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7dd512f84cf2504721318bb73c902c55f0976b2c`
- Runtime source hash: `cdcf8c02ed42fd9a3da6124346aff969f0d61fbc1e0efb45c8542b9bce6e52cd`
- Matching release tag: `runtime-7dd512f84cf2504721318bb73c902c55f0976b2c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
