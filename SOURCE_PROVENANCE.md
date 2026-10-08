# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `38207b066b3805470f7f05e703b2eb38a89f8ebf`
- Runtime source hash: `b9f5dd7771478c6bd3ba7cf865d981fe6dad45f5b782c6c90eb406ab77f1a7df`
- Matching release tag: `runtime-38207b066b3805470f7f05e703b2eb38a89f8ebf`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
