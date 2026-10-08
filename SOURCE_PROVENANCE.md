# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `4f19629d2f3d64a0a33adb83c2801295f1700d14`
- Runtime source hash: `8ef5c87e5b1863e5fa11a3d828d24ff6816e942994176c2338120b9d0a71812e`
- Matching release tag: `runtime-4f19629d2f3d64a0a33adb83c2801295f1700d14`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
