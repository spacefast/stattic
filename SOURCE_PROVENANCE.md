# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `656ad46f1dd33428d73cbd119f015ba8f4cfd70f`
- Runtime source hash: `1154ba60d020944cbbde37f90df30e129b12bc0de2e98753d40d8f7f55c3a888`
- Matching release tag: `runtime-656ad46f1dd33428d73cbd119f015ba8f4cfd70f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
