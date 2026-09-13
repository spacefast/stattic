# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `7d75aa9b1dd6e32f839e10c2230e47720dddbfc6`
- Runtime source hash: `c94f5bf78bff537defda203fe79a5d9758d8ab2889de23c0bbc19cfa36fac511`
- Matching release tag: `runtime-7d75aa9b1dd6e32f839e10c2230e47720dddbfc6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
