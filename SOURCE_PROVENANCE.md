# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `274193fc47acb6f9816d6a522afa80c2aebf871a`
- Runtime source hash: `c52f0e862b160fa31294d0dc5faff5cae45c799a5da59e20c595cfa779a37968`
- Matching release tag: `runtime-274193fc47acb6f9816d6a522afa80c2aebf871a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
