# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5a6ff88d82d58c7e2a4601a0001d8bdbe6b7efa8`
- Runtime source hash: `c4d89aaea770cc58708b9c2e68b2b7124c918ae285e6c8291b21ee5867e4de41`
- Matching release tag: `runtime-5a6ff88d82d58c7e2a4601a0001d8bdbe6b7efa8`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
