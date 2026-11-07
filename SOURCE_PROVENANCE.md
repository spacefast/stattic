# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `098c61f8c0ab80c94c908ab19ad5562b255a0e0c`
- Runtime source hash: `f2129a28eb6a220a81f201d2c550406b5434e177a723f0c45297edde4be1f875`
- Matching release tag: `runtime-098c61f8c0ab80c94c908ab19ad5562b255a0e0c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
