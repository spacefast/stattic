# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `002a6c1cfc2413fd6a00bfa07c3f6e0426554234`
- Runtime source hash: `919cf836579e7a8cbfd020ec8ee73c70e59e418f8170d0f069b160a1cfb89d75`
- Matching release tag: `runtime-002a6c1cfc2413fd6a00bfa07c3f6e0426554234`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
