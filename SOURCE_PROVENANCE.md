# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a37c7879e2340be7d34e496a399bcc79eefa5b54`
- Runtime source hash: `f81f85a3908498fe993b12ec7e8220523e23ae9964f25fd641603121bf48a609`
- Matching release tag: `runtime-a37c7879e2340be7d34e496a399bcc79eefa5b54`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
