# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1fc2d3b873642c2d0f685d98135fa7567427a782`
- Runtime source hash: `dd694ef8512e21feb73e09e139db43fe8bf92c9ad8c27c153e02d57f6e397b47`
- Matching release tag: `runtime-1fc2d3b873642c2d0f685d98135fa7567427a782`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
