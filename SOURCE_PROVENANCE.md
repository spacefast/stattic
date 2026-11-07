# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `f09a6bea7433054622487681ad6c6765eb75a1b4`
- Runtime source hash: `ac53c7eeed83ce57ee0265a822e1a39ad9e6ae6af59ae75336d5a572878c9952`
- Matching release tag: `runtime-f09a6bea7433054622487681ad6c6765eb75a1b4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
