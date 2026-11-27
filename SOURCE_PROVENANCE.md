# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e254d94f25804f023bbdf40d5f3ef4b201498696`
- Runtime source hash: `1bed65d13b34f033ba2e9eefa45d0e4a8c9dfe01e166bf51457614c67675ec28`
- Matching release tag: `runtime-e254d94f25804f023bbdf40d5f3ef4b201498696`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
