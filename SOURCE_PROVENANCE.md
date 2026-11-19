# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1008a4b2b7fe7d0b4faf60752f82602726c6be60`
- Runtime source hash: `ab483a031990a4bb1bc952c7842cb31bf2263eaf8f63bbcf8ca360e7a47af663`
- Matching release tag: `runtime-1008a4b2b7fe7d0b4faf60752f82602726c6be60`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
