# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `db6bd54088b6a96723e692d338754d45e28fb734`
- Runtime source hash: `49285e88a3db72964d851ef053d12968e22c01bf494da2547353b79e97d23d0e`
- Matching release tag: `runtime-db6bd54088b6a96723e692d338754d45e28fb734`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
