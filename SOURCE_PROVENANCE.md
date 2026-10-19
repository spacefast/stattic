# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `562aa394b15c68de9e4d2fdbd045f851b65d60c6`
- Runtime source hash: `7fdad2f578ac2be7471065af3cb542d9e1261b0f539ff56536b766f58756c0ad`
- Matching release tag: `runtime-562aa394b15c68de9e4d2fdbd045f851b65d60c6`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
