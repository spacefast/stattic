# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `86f6158c1dfd80295dd4e52a6572df4e8e962148`
- Runtime source hash: `09a46851b89a57afb51ebe70507362c5fd80b4d1840631ac6d5b62dbeb5a7b22`
- Matching release tag: `runtime-86f6158c1dfd80295dd4e52a6572df4e8e962148`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
