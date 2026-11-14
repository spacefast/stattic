# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5c2b0a108b3041519fa109a1c180af74fa4e9f38`
- Runtime source hash: `f84ec8668ce5e020f74aef3f5b70ad930395f0c8d26b572ad33fb973da71723f`
- Matching release tag: `runtime-5c2b0a108b3041519fa109a1c180af74fa4e9f38`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
