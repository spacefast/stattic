# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ac2e872fb7aa21d8beae60ce90dcdaa01e7aef9f`
- Runtime source hash: `19a55d0592167f98a9f60599804d6f360ebfd7993a3efa3f43b21a9116342ba6`
- Matching release tag: `runtime-ac2e872fb7aa21d8beae60ce90dcdaa01e7aef9f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
