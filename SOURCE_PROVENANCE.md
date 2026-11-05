# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `95fd85fbd0964875438e33db2d3dddf4fa77c3bc`
- Runtime source hash: `bae7af1ed65d3c55eacd548d704a95071b662993fc80921a842630c5b426b2ca`
- Matching release tag: `runtime-95fd85fbd0964875438e33db2d3dddf4fa77c3bc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
