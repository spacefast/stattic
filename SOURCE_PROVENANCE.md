# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bcdcdd5126c6b8af3f4f5c7aadf3cfeb1a865a1c`
- Runtime source hash: `79a22cef147a6f4b3991fe4afaec0a8349ccc4787ee69bc675a64320344039d4`
- Matching release tag: `runtime-bcdcdd5126c6b8af3f4f5c7aadf3cfeb1a865a1c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
