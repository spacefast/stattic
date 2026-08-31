# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `164e828663a48c98905e7a1788059da7c38f7f78`
- Runtime source hash: `ad9a06d4d3c5554dbd87a9d14756654a763daebf7374e66fde00bbd1f3821f73`
- Matching release tag: `runtime-164e828663a48c98905e7a1788059da7c38f7f78`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
