# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `498400a6e4ebe0adbf19ebe25844982e7da36900`
- Runtime source hash: `6729db07cbe4f7a6ef47268437c11bb79f5dd9abda13b72fc534e9e42867c2d5`
- Matching release tag: `runtime-498400a6e4ebe0adbf19ebe25844982e7da36900`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
