# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e3d140d606e618f80cef5269a8a04a9b8b4c4e2f`
- Runtime source hash: `4f5116d5e60afc411fac82d46ce28fc5bc2b22ef4b1ce9d44bc3f223afdb99d7`
- Matching release tag: `runtime-e3d140d606e618f80cef5269a8a04a9b8b4c4e2f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
