# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `50906ad0a0a025b286fc389e18c3a1b926dc96f4`
- Runtime source hash: `3463d88331ce71f1c95baf518370ca87b3e180a1aa0da03cd2f6e98bfc655b0b`
- Matching release tag: `runtime-50906ad0a0a025b286fc389e18c3a1b926dc96f4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
