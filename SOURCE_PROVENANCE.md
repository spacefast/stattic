# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `dcd7fdd9e5ed1a244b28c4def8a9e93162cfea9b`
- Runtime source hash: `0ec354d8da4d2c5763897a8f925786ccc6a12b9dc60a66cec995193ffd01fc5f`
- Matching release tag: `runtime-dcd7fdd9e5ed1a244b28c4def8a9e93162cfea9b`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
