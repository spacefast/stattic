# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fa66d9ca36c79e2610a1422eea7da0151524acb1`
- Runtime source hash: `16b62901b10c23e9cdc90e50f8d94ccca74ae2b2144cd6fba8775379de599ec3`
- Matching release tag: `runtime-fa66d9ca36c79e2610a1422eea7da0151524acb1`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
