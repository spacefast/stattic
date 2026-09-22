# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `274b0213c95e468bb941b6ee6824d8bff63eb732`
- Runtime source hash: `544d7c51f9ac2ac4ad572d0e0d441b3bbffc5e36d20441dbb55ab7ce5e5009e5`
- Matching release tag: `runtime-274b0213c95e468bb941b6ee6824d8bff63eb732`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
