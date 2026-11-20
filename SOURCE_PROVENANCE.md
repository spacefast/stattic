# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5e5a24107ba39de5d5a43cd1053901479d428977`
- Runtime source hash: `c68d4788eee5703ec500046348302f76bfc810a84d25fc554282ea2a8b75d232`
- Matching release tag: `runtime-5e5a24107ba39de5d5a43cd1053901479d428977`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
