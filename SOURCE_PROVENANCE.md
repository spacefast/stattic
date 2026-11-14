# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2c7e4db1bf0c966b21962c0ed20b0064722d8510`
- Runtime source hash: `f4b80a236bb29fc14d9e86fc585afb146687e109bf792737a9ca7e172ecdd8ee`
- Matching release tag: `runtime-2c7e4db1bf0c966b21962c0ed20b0064722d8510`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
