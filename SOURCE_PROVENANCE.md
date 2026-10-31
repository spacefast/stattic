# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3fcb8b8d5f4184e17e89952cf417dc4c43b63d71`
- Runtime source hash: `b4a1f22d5a20f5c5940de03e4169086d40460e8c077fc12c4b55ba59913cef8e`
- Matching release tag: `runtime-3fcb8b8d5f4184e17e89952cf417dc4c43b63d71`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
