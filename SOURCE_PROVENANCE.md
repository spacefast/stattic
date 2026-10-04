# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6e92820048763638d65e3389a830876e569f67e3`
- Runtime source hash: `9c0c72eb5244a5877169fa0267c673fad4b8920acf66fd16a441a1e88c900207`
- Matching release tag: `runtime-6e92820048763638d65e3389a830876e569f67e3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
