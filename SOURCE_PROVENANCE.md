# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `63bc84a4884bef77f8f4adbe0df9190f76057a1e`
- Runtime source hash: `a5d98d3efdb70f02f1e18bb42d9609a25abff30d05c65cb3146eaeb8d1c9e262`
- Matching release tag: `runtime-63bc84a4884bef77f8f4adbe0df9190f76057a1e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
