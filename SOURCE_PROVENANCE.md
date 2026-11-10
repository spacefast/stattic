# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `9b6150cd46aef07c1b4b01721f63e42a80d8e2b4`
- Runtime source hash: `09a46851b89a57afb51ebe70507362c5fd80b4d1840631ac6d5b62dbeb5a7b22`
- Matching release tag: `runtime-9b6150cd46aef07c1b4b01721f63e42a80d8e2b4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
