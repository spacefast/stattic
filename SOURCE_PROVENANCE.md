# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6b2606a04697946d5a50e1ba52862f8f349edcb2`
- Runtime source hash: `ca10e7b1e13e9716a6aad05cc8e50d90853f14a773226f78f030a84e7e248eee`
- Matching release tag: `runtime-6b2606a04697946d5a50e1ba52862f8f349edcb2`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
