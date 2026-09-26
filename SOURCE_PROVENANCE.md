# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c3d5e171a342e24f0dcaac9c069213b563c1413a`
- Runtime source hash: `ab82da3e165984358ba7caf810e59cf92e463fbad5d278e356f12aa716206480`
- Matching release tag: `runtime-c3d5e171a342e24f0dcaac9c069213b563c1413a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
