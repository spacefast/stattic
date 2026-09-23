# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `2acaa64e71b4e8478b40ba6812ca44efa72de024`
- Runtime source hash: `666c0978ac7588e8b8feafa38f7eaa2e160a293733dce9b0468846d777761c09`
- Matching release tag: `runtime-2acaa64e71b4e8478b40ba6812ca44efa72de024`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
