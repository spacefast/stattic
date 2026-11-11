# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0f8e50d8829ac7bd2a7ccf2547db20cff325a6e3`
- Runtime source hash: `0fa42b81c0a1dea8f5fff3bafd8c1a838cffe97db5b754ac15d1a7ae1516c75a`
- Matching release tag: `runtime-0f8e50d8829ac7bd2a7ccf2547db20cff325a6e3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
