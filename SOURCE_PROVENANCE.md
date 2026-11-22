# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d3d380929faef2a5456c45508e43a97f6e5668e8`
- Runtime source hash: `8c5e326e854382768aaa451e6b974bd55be32b1e67cc8da53086e52fbdd30767`
- Matching release tag: `runtime-d3d380929faef2a5456c45508e43a97f6e5668e8`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
