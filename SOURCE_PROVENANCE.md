# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bbd33703b6ca07aeb49404149589e43ac802e63e`
- Runtime source hash: `661a63096c68fb61f1da7aa8edf16d1a012ed8549f7bf71c3a19790694f40f40`
- Matching release tag: `runtime-bbd33703b6ca07aeb49404149589e43ac802e63e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
