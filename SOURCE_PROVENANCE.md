# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bd02c87b4bbd88cfe35d0745ac5281c68c13d27b`
- Runtime source hash: `447ea5d9dc1bc63918c6418f8e96503e746a7aedd7dd6e03e033a765ba10f98c`
- Matching release tag: `runtime-bd02c87b4bbd88cfe35d0745ac5281c68c13d27b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
