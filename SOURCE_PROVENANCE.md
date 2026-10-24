# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1ecc178aad187954c8abe85896cdd9a990df9289`
- Runtime source hash: `4013db8b6fb8ece61881afe9a66fd4e84d88cfd34614d44640d6269591c433df`
- Matching release tag: `runtime-1ecc178aad187954c8abe85896cdd9a990df9289`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
