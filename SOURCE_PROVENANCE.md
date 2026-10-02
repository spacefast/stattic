# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8c1d3158853d24a963ed4aa2ab50e12b457062f0`
- Runtime source hash: `a751e834d943d67f6b3919ae365b25004ed8acb444d76b54647c9715da1f950f`
- Matching release tag: `runtime-8c1d3158853d24a963ed4aa2ab50e12b457062f0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
