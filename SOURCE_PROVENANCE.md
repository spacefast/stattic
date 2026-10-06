# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ca528525c8d81485ded390c6ccf55b2f9a4ee535`
- Runtime source hash: `9d7c158b5dade725b095be707e88ade40743317234d3afe24c0d5a01c011e200`
- Matching release tag: `runtime-ca528525c8d81485ded390c6ccf55b2f9a4ee535`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
