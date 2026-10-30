# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `828834b34b6a7c214a94c332fbadb107c43a5b37`
- Runtime source hash: `cca1ad6fa74a45cc19abce0f4c493f3839902ac8b28ee49317910092beb40d4f`
- Matching release tag: `runtime-828834b34b6a7c214a94c332fbadb107c43a5b37`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
