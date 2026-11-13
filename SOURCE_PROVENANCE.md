# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `02a46a409123f18f7e5b2c98e34c30e6e3f3bf63`
- Runtime source hash: `dd351df0f7cf9f39826a82e26a6c7c05e9cc994dfc49d952f8f83367e08c6ca6`
- Matching release tag: `runtime-02a46a409123f18f7e5b2c98e34c30e6e3f3bf63`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
