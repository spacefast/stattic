# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `18d27541338c87fc91be51369f8d5c315f260f2c`
- Runtime source hash: `4e755bf31bd630308f54f3db99747acf85bd7e03ebf74e78009c3751801d1eff`
- Matching release tag: `runtime-18d27541338c87fc91be51369f8d5c315f260f2c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
