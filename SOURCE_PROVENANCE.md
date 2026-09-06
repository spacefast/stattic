# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4946a7c4e09a127a084ababc37c834151a575714`
- Runtime source hash: `2e6006a2c7499da9cff63e237375ddd8d37d31f1f256437c58bbb22c903f9382`
- Matching release tag: `runtime-4946a7c4e09a127a084ababc37c834151a575714`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
