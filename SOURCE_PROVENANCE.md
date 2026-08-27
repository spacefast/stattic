# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e70c60c1524b4f944bd95775974b70fbe3326650`
- Runtime source hash: `ffbc8fdd721342a41ad54e070cfbc8a6f55d1038d97cfecb6909e936bcd15cc2`
- Matching release tag: `runtime-e70c60c1524b4f944bd95775974b70fbe3326650`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
