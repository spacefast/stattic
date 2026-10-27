# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0b3770d0f20a5e99b1627546e206ecd7b009a1bd`
- Runtime source hash: `0d7abf986dbc84d9e5b99c97840df5f3b166c93ee07f6f3eb91622bb36182300`
- Matching release tag: `runtime-0b3770d0f20a5e99b1627546e206ecd7b009a1bd`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
