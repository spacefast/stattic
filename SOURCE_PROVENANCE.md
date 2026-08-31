# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3b5a0a70a86c6fe630b623889a0b37858fd7f0b6`
- Runtime source hash: `df5c543348b06a240668d060a1fc35714b7d7eaf73ac5d352a8a8ef60a3c7a07`
- Matching release tag: `runtime-3b5a0a70a86c6fe630b623889a0b37858fd7f0b6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
