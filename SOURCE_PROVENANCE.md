# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `acde251f8082268ced45dec46fa8b212dfc1f254`
- Runtime source hash: `acedff007b4cc7f19ab878b2f9debb28dd2ecb6a5949064e6d9a43bacc12e703`
- Matching release tag: `runtime-acde251f8082268ced45dec46fa8b212dfc1f254`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
