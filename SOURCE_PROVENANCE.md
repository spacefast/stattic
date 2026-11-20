# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `ef786ed446a2d133e15e351a3cc36471a7e28df3`
- Runtime source hash: `72f7b10255ecc05da0d23996731d7afd906e3cdf44b1d98efbf406054444c284`
- Matching release tag: `runtime-ef786ed446a2d133e15e351a3cc36471a7e28df3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
