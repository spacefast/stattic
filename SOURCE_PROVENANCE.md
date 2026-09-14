# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `06e0ad9948efbd1e66b308cbe78f5c7e2d22114b`
- Runtime source hash: `5b965305baa789827cab70ce76104223c34b518d03402ff24d9bfa0cdb134846`
- Matching release tag: `runtime-06e0ad9948efbd1e66b308cbe78f5c7e2d22114b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
