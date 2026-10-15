# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6e47e5602402bd04633d77cc2d1d06c19d95745a`
- Runtime source hash: `e047867ab941bd351e08cdb76ec1a0184005f7bbc89ce75831c6c1f14b087a4d`
- Matching release tag: `runtime-6e47e5602402bd04633d77cc2d1d06c19d95745a`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
