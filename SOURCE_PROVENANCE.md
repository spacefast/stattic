# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3b84e071fd138cc0b8dca3cf20864b3d1788c920`
- Runtime source hash: `aed64bdf3d6634c634f67de51774a4ad5e731f2e7881af7565ce7761c5fad1d7`
- Matching release tag: `runtime-3b84e071fd138cc0b8dca3cf20864b3d1788c920`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
