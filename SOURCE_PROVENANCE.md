# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4ed499feee792750f5d78664f0775e9435077938`
- Runtime source hash: `0b5ca788241e2b5209c14e4b3f0affbe8ee8398c4ba2d3cda27a752d96283d04`
- Matching release tag: `runtime-4ed499feee792750f5d78664f0775e9435077938`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
