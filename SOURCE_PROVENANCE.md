# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `633f3228f9ca34409e230feabc95d0c4185fec0f`
- Runtime source hash: `7764328831c713c59916153be1c04234c4477c4313560349a4b72b82eac89937`
- Matching release tag: `runtime-633f3228f9ca34409e230feabc95d0c4185fec0f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
