# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a6da527bb570ed5b2c7e9bf077cb25ffeaf1c4fc`
- Runtime source hash: `78f29fe662b75d5c05db9250284555d5fca2872a8b999f9546670e725c7b6d3d`
- Matching release tag: `runtime-a6da527bb570ed5b2c7e9bf077cb25ffeaf1c4fc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
