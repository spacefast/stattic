# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `418858becd716cf0f867877e68f99fb5a8895a7e`
- Runtime source hash: `8ef5c87e5b1863e5fa11a3d828d24ff6816e942994176c2338120b9d0a71812e`
- Matching release tag: `runtime-418858becd716cf0f867877e68f99fb5a8895a7e`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
