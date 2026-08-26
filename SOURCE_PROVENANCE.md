# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2a4a9302fa58427e9131b501a4636ff3d79cd35d`
- Runtime source hash: `247bf937a13d08f62cb2f42dac8ac36b04b15c234e8f7ed9e7783e6e925a106c`
- Matching release tag: `runtime-2a4a9302fa58427e9131b501a4636ff3d79cd35d`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
