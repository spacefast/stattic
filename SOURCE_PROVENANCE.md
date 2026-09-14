# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ca5197a9bb84da67edcf24d594d0fcb5648e95aa`
- Runtime source hash: `c188221136e64deb08388efe5b8e43f14de3f634c5684845ffc8a87716c869c9`
- Matching release tag: `runtime-ca5197a9bb84da67edcf24d594d0fcb5648e95aa`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
