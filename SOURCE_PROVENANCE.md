# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e8b364a3e088fa4445af75c06a920ce0597037db`
- Runtime source hash: `4bf1c8cc4ab4a8484677ba43b0bbbdee6e771f59e87c81c876db12b1b0bd6699`
- Matching release tag: `runtime-e8b364a3e088fa4445af75c06a920ce0597037db`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
