# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `374433a1e26f0e83bd3185cc0ef02b72e86d9b72`
- Runtime source hash: `d9dfac8c4eac6eb22eae6ae55ac30051efd882ad7aa08ae27ac9a95be8ee03fe`
- Matching release tag: `runtime-374433a1e26f0e83bd3185cc0ef02b72e86d9b72`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
