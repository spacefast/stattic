# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c6873736c066ebd65b7344beb18866e8c9f7c047`
- Runtime source hash: `1ea91aa10f13c3c90c199691954d432175eecc6e143935b179d750edfdfa1ba6`
- Matching release tag: `runtime-c6873736c066ebd65b7344beb18866e8c9f7c047`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
