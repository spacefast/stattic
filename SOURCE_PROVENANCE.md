# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `abf1f994e4d3837e9cd9f4b1f2577df1863b266f`
- Runtime source hash: `b8ce43ea533729d467431c089cc517d24b8b002ecd4c5a19d39fe51b65be69e2`
- Matching release tag: `runtime-abf1f994e4d3837e9cd9f4b1f2577df1863b266f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
