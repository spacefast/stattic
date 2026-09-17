# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6822f108154f787c2081b008f0944a56b2813e58`
- Runtime source hash: `2fa74c6257945dcd14db10d7e65b301fbd2eb2e6c9dd382918368693884c90d6`
- Matching release tag: `runtime-6822f108154f787c2081b008f0944a56b2813e58`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
