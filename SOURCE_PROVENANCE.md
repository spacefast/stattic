# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1c1cbb13afd201d4feb3000813c0ac19b85cd749`
- Runtime source hash: `b81ce37e622d20170a484ea70b0e36fbe2c8a73196e2a8680f7d2214e9f148ee`
- Matching release tag: `runtime-1c1cbb13afd201d4feb3000813c0ac19b85cd749`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
