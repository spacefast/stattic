# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `cc7ce6d8d0eab7103a803ff3cf55a4d3a0bd096f`
- Runtime source hash: `90342f72e50c40eb881c24df5fb4102216221ed413d5ea6890293ea1ce793ead`
- Matching release tag: `runtime-cc7ce6d8d0eab7103a803ff3cf55a4d3a0bd096f`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
