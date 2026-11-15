# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6130629a514772a66e0320f5c047fceebe480177`
- Runtime source hash: `d6efceb473529163af524f6e03a61b02917b7a847c9cf090845242698bf8e552`
- Matching release tag: `runtime-6130629a514772a66e0320f5c047fceebe480177`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
