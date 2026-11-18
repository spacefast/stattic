# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `12ba7dc664c84123aac192fb4c861a62fb9598ee`
- Runtime source hash: `29bb959e63d3db94bbda53f875b12d27e643c126845294c651dc42241a6f9eea`
- Matching release tag: `runtime-12ba7dc664c84123aac192fb4c861a62fb9598ee`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
