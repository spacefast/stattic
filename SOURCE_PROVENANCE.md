# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `87873e3f1806479a8a92bad4fe1b5dd5e04ed998`
- Runtime source hash: `4f5116d5e60afc411fac82d46ce28fc5bc2b22ef4b1ce9d44bc3f223afdb99d7`
- Matching release tag: `runtime-87873e3f1806479a8a92bad4fe1b5dd5e04ed998`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
