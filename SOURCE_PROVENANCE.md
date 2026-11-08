# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c3cb49029591483dd2fdd401964e428a4620ed23`
- Runtime source hash: `21753bf2d5ed80d6646b82a624dabb3bfa5590835dd0757409bc6e5943d3b992`
- Matching release tag: `runtime-c3cb49029591483dd2fdd401964e428a4620ed23`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
