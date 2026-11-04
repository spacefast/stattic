# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6292023f1b40f59549c69928a0742e32613c4abc`
- Runtime source hash: `2f9eeca7218d76c31dd911df69c15e5a2f23fbc3b0171d4460019cce5001e535`
- Matching release tag: `runtime-6292023f1b40f59549c69928a0742e32613c4abc`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
