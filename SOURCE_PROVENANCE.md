# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `73e07be524a155d02f76a8dc70a6b71546ffa76e`
- Runtime source hash: `5338d7419921dcf5c70c09c7273fa421291d52e7df0f2942ec19ee1364b27920`
- Matching release tag: `runtime-73e07be524a155d02f76a8dc70a6b71546ffa76e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
