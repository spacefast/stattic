# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `286f11318c8473635f0446f1f346007851be676c`
- Runtime source hash: `2603f7813e75e4690ae31070a8119a0920a0643cf35ef6452673a28b076f8026`
- Matching release tag: `runtime-286f11318c8473635f0446f1f346007851be676c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
