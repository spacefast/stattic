# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `334421c60396186349d54053749b423d0b2ed4e3`
- Runtime source hash: `481e6dc19f3156464a45a272d93f19fed6fc310074f9cf329a20e194404cb42f`
- Matching release tag: `runtime-334421c60396186349d54053749b423d0b2ed4e3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
