# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `783f622c9a05934afa2f21783d424dfec09edab3`
- Runtime source hash: `ce43270c0414b0d90f9ef7ef0754666a6eea484b25e97208e13bf92c79acd8b8`
- Matching release tag: `runtime-783f622c9a05934afa2f21783d424dfec09edab3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
