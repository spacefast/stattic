# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `657492977b21295e90bd071a83a7947bccd55e95`
- Runtime source hash: `0cf8c862b9a2ed622a00a44188b3cdfbc13dac3f12be9ebd72f6594edcecd9a6`
- Matching release tag: `runtime-657492977b21295e90bd071a83a7947bccd55e95`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
