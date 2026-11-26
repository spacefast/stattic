# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `f03f0be5f5bfc3d9ac6092d62fed1029b6f472bc`
- Runtime source hash: `2916d5394ef7ef2a825ddbcd761f8d3ddea9c00a708960e54ffdfed27e27bd37`
- Matching release tag: `runtime-f03f0be5f5bfc3d9ac6092d62fed1029b6f472bc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
