# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a4d814b0d26da67c0bc1c2e59cde4e25b939961f`
- Runtime source hash: `9d8fb66243d13617c6fc2df23fe796ea6b61f48fc92c783522b9a144e81c1bd6`
- Matching release tag: `runtime-a4d814b0d26da67c0bc1c2e59cde4e25b939961f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
