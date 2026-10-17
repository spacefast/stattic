# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a6027624e4c68d209fbc7ae237f480de5a725345`
- Runtime source hash: `e7f1242989ab2578d4e2d756531e257393f2ee9faea500ae58c821a5cb110620`
- Matching release tag: `runtime-a6027624e4c68d209fbc7ae237f480de5a725345`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
