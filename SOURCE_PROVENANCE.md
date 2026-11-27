# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `86591524d403b6aa09a58ac5f59a3934099c2eb0`
- Runtime source hash: `2ac9cc97e5dfeb8bd6eb1018930251fd1aaa5158b72a64cbe9478c239500901c`
- Matching release tag: `runtime-86591524d403b6aa09a58ac5f59a3934099c2eb0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
