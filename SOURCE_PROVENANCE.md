# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `92814f5977d9adcdc0501b26f563901ed271b678`
- Runtime source hash: `b129f510a60990ac398c5974015b571b46d7354102e504d35832da31980d44cd`
- Matching release tag: `runtime-92814f5977d9adcdc0501b26f563901ed271b678`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
