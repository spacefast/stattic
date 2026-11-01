# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `fbd1e9fff4383e65f7878a7f6c96933240d1e00c`
- Runtime source hash: `0231aa98efaae8d7869c79981a28fe4a9a0e710de69a9831664ae6147969e9f2`
- Matching release tag: `runtime-fbd1e9fff4383e65f7878a7f6c96933240d1e00c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
