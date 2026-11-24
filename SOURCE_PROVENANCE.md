# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6e6fdb4fc22b6bb4a29d08dc25eb200164d632a7`
- Runtime source hash: `4b494d851af6a9f3b2648cdb34c03e589a123f34ea53ec21b63104aa802d69f5`
- Matching release tag: `runtime-6e6fdb4fc22b6bb4a29d08dc25eb200164d632a7`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
