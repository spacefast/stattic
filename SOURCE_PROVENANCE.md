# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6d900b12a56d473270258ec57d3cbe69459eb621`
- Runtime source hash: `6f32709f9ac5901a55f219b78f0e7bc157ba99cbf33cba7e1fdf13eaea038136`
- Matching release tag: `runtime-6d900b12a56d473270258ec57d3cbe69459eb621`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
