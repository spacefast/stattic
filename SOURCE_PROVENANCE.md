# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fef0fcacbadc6ca469e5daae1260645f449007ec`
- Runtime source hash: `f7423e8dbaa19a9ef4b9bd2f19b303ceb205d6c46195e1a54d6e32a22122206e`
- Matching release tag: `runtime-fef0fcacbadc6ca469e5daae1260645f449007ec`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
