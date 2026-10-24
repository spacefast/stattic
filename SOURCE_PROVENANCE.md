# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a2d62dcca459c9248bb953e8f3a61d42774424fd`
- Runtime source hash: `c382814e8b552143e68b04dd0ec4f0fe611314ade7db1f515f1081ecc11bbfba`
- Matching release tag: `runtime-a2d62dcca459c9248bb953e8f3a61d42774424fd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
