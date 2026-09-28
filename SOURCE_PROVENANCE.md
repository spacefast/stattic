# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `aa882f77e669de07b98eab17265bb38cfd448cb4`
- Runtime source hash: `75c25d7283aa8b08f83ef77ae820b63fa45bb4bc260d5cd75b41c5250f057784`
- Matching release tag: `runtime-aa882f77e669de07b98eab17265bb38cfd448cb4`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
