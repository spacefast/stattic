# Source provenance

The `main` branch is an automated, allowlisted source history of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `0738b2b08e17c85629722a6f44f724994a781ba3`
- Runtime source hash: `0a575bfa3a364785f278b59f9d79ed23877a167ee1ab9f1f01b466fd5b03e498`
- Matching release tag: `runtime-0738b2b08e17c85629722a6f44f724994a781ba3`

The private monorepo is the development authority. Every published source
change appends an automated commit to this one-way public mirror. GitHub Actions
are intentionally disabled for this repository, and the exported tree contains
no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
