# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cabbe8cb453e17fa77586dca1ebdb6443daeaf69`
- Runtime source hash: `5002be205a9b760cf0060d82163e66b44c843ae0237ee95673230f2f0a608702`
- Matching release tag: `runtime-cabbe8cb453e17fa77586dca1ebdb6443daeaf69`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
