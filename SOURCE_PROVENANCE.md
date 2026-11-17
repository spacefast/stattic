# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3ac1d4f93d05c97da90e51af4a38f4984a5c3435`
- Runtime source hash: `447ea5d9dc1bc63918c6418f8e96503e746a7aedd7dd6e03e033a765ba10f98c`
- Matching release tag: `runtime-3ac1d4f93d05c97da90e51af4a38f4984a5c3435`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
