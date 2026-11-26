# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4c42afec544a8e0b83db8e2c1665504476db625f`
- Runtime source hash: `a32b5f2fb20dc0934967f734f92aea06b8c12e03b3ba8f5406bbe772be1890c1`
- Matching release tag: `runtime-4c42afec544a8e0b83db8e2c1665504476db625f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
