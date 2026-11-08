# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4dd99e16456fc5de72a092f7e5679e7dd4ededeb`
- Runtime source hash: `63b96ae3d51ed1d1ab384fc5390c2d7433c384808daff034713a8394c4dfacc4`
- Matching release tag: `runtime-4dd99e16456fc5de72a092f7e5679e7dd4ededeb`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
