# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `047a3a75541e6127e89667db56e531da7da54212`
- Runtime source hash: `6a9de2ed58d344c29957320c6a2f12af1c5e439d283719ceaff8edb2e0cb879d`
- Matching release tag: `runtime-047a3a75541e6127e89667db56e531da7da54212`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
