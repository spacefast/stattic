# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `85d188d5e4e7215821ab68c7b7b3a7e2364ae64c`
- Runtime source hash: `e4f5b42c8d782782590b53f6210560e5c63e08d59bd3e4eff2b008f67e527de1`
- Matching release tag: `runtime-85d188d5e4e7215821ab68c7b7b3a7e2364ae64c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
