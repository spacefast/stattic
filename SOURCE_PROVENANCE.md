# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `8af432894505cdd6d80a56e16f6184a3c833ccde`
- Runtime source hash: `c38804e41880173da6aef1811fba73e909af6692a1f453c6593161702361ca43`
- Matching release tag: `runtime-8af432894505cdd6d80a56e16f6184a3c833ccde`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
