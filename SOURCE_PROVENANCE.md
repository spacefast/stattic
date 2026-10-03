# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `634b19f2333a7914fc91ff9f70e74ee8408742b6`
- Runtime source hash: `cb2d11a970f87c591468d267209a2a00f1075fd61e3154102b791d4d2ea43417`
- Matching release tag: `runtime-634b19f2333a7914fc91ff9f70e74ee8408742b6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
