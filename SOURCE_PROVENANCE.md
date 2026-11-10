# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e43fcdcfe6bee7c64cf402ca7d30a2694b0fa179`
- Runtime source hash: `09a46851b89a57afb51ebe70507362c5fd80b4d1840631ac6d5b62dbeb5a7b22`
- Matching release tag: `runtime-e43fcdcfe6bee7c64cf402ca7d30a2694b0fa179`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
