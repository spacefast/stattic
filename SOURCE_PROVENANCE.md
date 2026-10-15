# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `dbdfc0c4160525be724aa20c623f57ee4d4ec59c`
- Runtime source hash: `698118cd7cce4afdc2934ef47e42db6a8779854c9714fc263a453ec28cca06f2`
- Matching release tag: `runtime-dbdfc0c4160525be724aa20c623f57ee4d4ec59c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
