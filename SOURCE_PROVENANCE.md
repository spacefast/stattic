# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6e6aaa55e82c26ae5ae77654cb268c4eace06ceb`
- Runtime source hash: `08b2deac1a10416b48a8542e4aaf12a38f18bcbcd96b906dfd3f77a23d1cb435`
- Matching release tag: `runtime-6e6aaa55e82c26ae5ae77654cb268c4eace06ceb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
