# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cb1948bc3571aac0c5190b29bf4b1ec62b90f10c`
- Runtime source hash: `9d8fb66243d13617c6fc2df23fe796ea6b61f48fc92c783522b9a144e81c1bd6`
- Matching release tag: `runtime-cb1948bc3571aac0c5190b29bf4b1ec62b90f10c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
