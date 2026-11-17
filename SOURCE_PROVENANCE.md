# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `bc8335f220df6e925dbad99ca904c2a1ca570521`
- Runtime source hash: `42971728b2155a7273e25fd6cdf236b1ab9413732c54fce13e125f6282180b91`
- Matching release tag: `runtime-bc8335f220df6e925dbad99ca904c2a1ca570521`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
