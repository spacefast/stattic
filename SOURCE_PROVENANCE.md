# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b83ce8c6ccaac4fa0b9e25905c1fe3b84f5bf183`
- Runtime source hash: `affb30a9797dfbbb5bb56a15b64abf5e52f03009272267944a5c109ab3bf81e1`
- Matching release tag: `runtime-b83ce8c6ccaac4fa0b9e25905c1fe3b84f5bf183`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
