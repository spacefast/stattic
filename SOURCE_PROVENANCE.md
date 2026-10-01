# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `e5c89b67f4550a6c1d05413775466bfb3b0f6525`
- Runtime source hash: `cbb721cf69b6e09f7979d52c5c676573ec901bb68b0cfaa75952bb69669ce5c2`
- Matching release tag: `runtime-e5c89b67f4550a6c1d05413775466bfb3b0f6525`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
