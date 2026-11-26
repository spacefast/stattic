# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e98a83cd4611241aad4fe96f44c5a025bb2a3c2f`
- Runtime source hash: `44551fb27c51c3d1e2f76bc4ab8b56b34aa6d9a69e287c129a84c87524cf4334`
- Matching release tag: `runtime-e98a83cd4611241aad4fe96f44c5a025bb2a3c2f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
