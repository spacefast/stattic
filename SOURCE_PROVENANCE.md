# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4923971af0e737b3f2d2aa6d74683e2fc0ccb632`
- Runtime source hash: `b936bf15cc10494dda092b6788b02ff429dcf71f99a5547a708709b158059453`
- Matching release tag: `runtime-4923971af0e737b3f2d2aa6d74683e2fc0ccb632`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
