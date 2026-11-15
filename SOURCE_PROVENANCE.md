# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a40bb6a8826a61952f9cf5abdb7a79e6688bd40d`
- Runtime source hash: `f10c89925db1bc34025ceb9e6b842d40374e4b6a44ed8fa452a18534f869c72c`
- Matching release tag: `runtime-a40bb6a8826a61952f9cf5abdb7a79e6688bd40d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
