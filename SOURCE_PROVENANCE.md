# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3f303db1fe1a93831093c8ef4ee84ec17ab9efe4`
- Runtime source hash: `c77f602e48807e20af2dabcc6cc4098e9d1594ca3a2fb50f843ac98894714413`
- Matching release tag: `runtime-3f303db1fe1a93831093c8ef4ee84ec17ab9efe4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
