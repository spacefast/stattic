# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ef842d668f3a986cb26a02a7001d35516a91a936`
- Runtime source hash: `1fa98631348fcd3a9cca717a5fe788092a455612f0484c0370749bb36b9cabaa`
- Matching release tag: `runtime-ef842d668f3a986cb26a02a7001d35516a91a936`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
