# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `63ac6842d4c6370a79bf801499fe0c01aec36226`
- Runtime source hash: `d2e3cc97898c1d2473059ada5e239f0ffab3c58339cd87efaf6eaae9d5ad4851`
- Matching release tag: `runtime-63ac6842d4c6370a79bf801499fe0c01aec36226`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
