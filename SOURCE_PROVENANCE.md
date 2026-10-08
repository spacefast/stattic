# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7d93e7261f95d08391b602d30a179338dc0be7cb`
- Runtime source hash: `a87f1711a2f31cb8ea5469296fe690499cac0bc1b86a5bb618607cddf6f7e976`
- Matching release tag: `runtime-7d93e7261f95d08391b602d30a179338dc0be7cb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
