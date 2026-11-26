# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9df7bce2256929f3ed0cc79e79c6242cb0c4b08f`
- Runtime source hash: `d9605395a8b27e7b91e8c5e1ebfe84cc5317e85457da1c1c45b28a7ee439dd30`
- Matching release tag: `runtime-9df7bce2256929f3ed0cc79e79c6242cb0c4b08f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
