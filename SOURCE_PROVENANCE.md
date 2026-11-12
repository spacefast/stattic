# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `156b85a7854447555ecab3772c7f43fa9ca32720`
- Runtime source hash: `06ae0ad619f20bd6fe06965c3e81f1bbb7ef31f6616ec0e798f479d126a78345`
- Matching release tag: `runtime-156b85a7854447555ecab3772c7f43fa9ca32720`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
