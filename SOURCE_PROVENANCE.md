# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4db3eea81ae4a73f9745323af9a37e77b883b59a`
- Runtime source hash: `15ad4656b9a5e553241ca01660a4992a62d2a095e25c8c04a0e8bb535119a4ee`
- Matching release tag: `runtime-4db3eea81ae4a73f9745323af9a37e77b883b59a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
