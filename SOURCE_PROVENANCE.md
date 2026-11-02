# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b83b5a872eb7a315a12c5e01af8906ffdaa2224e`
- Runtime source hash: `ac18a257a08f7cb11890c830fda5b10b7e9cfc6c23a0a068368672a8a7c9823b`
- Matching release tag: `runtime-b83b5a872eb7a315a12c5e01af8906ffdaa2224e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
