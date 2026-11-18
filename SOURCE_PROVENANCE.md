# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `5e56dc875af7692dfbde36adc2e551eaca38de5f`
- Runtime source hash: `1535cdaeb3b3232acbbbca9d636935e5617ee8c60289728ccc1f24bbf0937496`
- Matching release tag: `runtime-5e56dc875af7692dfbde36adc2e551eaca38de5f`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
