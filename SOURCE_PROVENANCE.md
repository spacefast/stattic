# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7ac890b251d8b8a3731a81ee365ef510eea6598a`
- Runtime source hash: `e8bed1d9fb8699de68cddb3dcd68bad837bac999372095a2360a281d17e0b9de`
- Matching release tag: `runtime-7ac890b251d8b8a3731a81ee365ef510eea6598a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
