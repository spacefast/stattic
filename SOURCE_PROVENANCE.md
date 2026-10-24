# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `89bd4f21d3bab63818651ee7bba05d84900cd6eb`
- Runtime source hash: `fdff4ab333820f9b5305e6349f1ae30266e9641910a063a5824a5222105f33ff`
- Matching release tag: `runtime-89bd4f21d3bab63818651ee7bba05d84900cd6eb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
