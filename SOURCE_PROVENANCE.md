# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `c3632c55718ad2ca7ed1a8bf83199159b02d0238`
- Runtime source hash: `725677a6ae4e728898a76457175c74f54aff28ecdfd1af7aaf939317725ab018`
- Matching release tag: `runtime-c3632c55718ad2ca7ed1a8bf83199159b02d0238`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
