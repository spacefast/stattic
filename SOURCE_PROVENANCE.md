# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `a782a69ab815b74b60ccade5231de359762714e0`
- Runtime source hash: `812b2358ba534d6ccf0c45ae140717d45640accbf6f130d26ad3174bff767b6b`
- Matching release tag: `runtime-a782a69ab815b74b60ccade5231de359762714e0`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
