# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d1a2a86d6905ebade943e221c46829a4ed9a87c5`
- Runtime source hash: `d9dfac8c4eac6eb22eae6ae55ac30051efd882ad7aa08ae27ac9a95be8ee03fe`
- Matching release tag: `runtime-d1a2a86d6905ebade943e221c46829a4ed9a87c5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
