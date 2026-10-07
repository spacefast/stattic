# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `d236ca4bc18084cf3959438f1699cd6881903104`
- Runtime source hash: `cffde7d76412e5c014f3807b0a0609476276267fa93d6ee022f41f883f865db4`
- Matching release tag: `runtime-d236ca4bc18084cf3959438f1699cd6881903104`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
