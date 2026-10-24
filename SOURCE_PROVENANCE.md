# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `14c15310cc42fbbd47af1cbb91c2480bf25eab7f`
- Runtime source hash: `fb78a9bb872d53980d8c11190a5223e8ef8205dd8f2af7e8b7496d8dd9570124`
- Matching release tag: `runtime-14c15310cc42fbbd47af1cbb91c2480bf25eab7f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
