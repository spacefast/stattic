# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bc1ccc6c1443516815c2e394706a59044be9ff33`
- Runtime source hash: `8aa8024b43d2903bd82cdc71ca837ca5ed24c2f1502c7940df7d525e3976bdea`
- Matching release tag: `runtime-bc1ccc6c1443516815c2e394706a59044be9ff33`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
