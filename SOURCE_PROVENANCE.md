# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `3b05ccaecd18b04f5e03d3315340f749b900c52b`
- Runtime source hash: `ecf8eae9c9c90243704c2f39b71715c0e851e412f3c6b420d6de9ff157b1be11`
- Matching release tag: `runtime-3b05ccaecd18b04f5e03d3315340f749b900c52b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
