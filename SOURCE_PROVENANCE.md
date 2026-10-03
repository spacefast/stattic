# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `81ad7d717a2ae64c49ae5a7beb8b0332ca12122a`
- Runtime source hash: `efb969ebf63b61cd23e8c88a12d188439855a234496cd306deccc0beedebabf5`
- Matching release tag: `runtime-81ad7d717a2ae64c49ae5a7beb8b0332ca12122a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
