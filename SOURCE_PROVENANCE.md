# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0362273d8a35dd25e075fa8e9b4af80882f38e4c`
- Runtime source hash: `c9a72f8cc6539a2a90503e61ce8ca09f96d37b624c310e08735ce93a407e9346`
- Matching release tag: `runtime-0362273d8a35dd25e075fa8e9b4af80882f38e4c`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
