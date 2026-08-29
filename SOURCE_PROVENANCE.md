# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `1f81344defcd7a91696a28af392c08ad76c500c5`
- Runtime source hash: `311c9844cc2a86ec7758e33100a64f87328ff864bc18658d13b2b471b0b843e4`
- Matching release tag: `runtime-1f81344defcd7a91696a28af392c08ad76c500c5`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
