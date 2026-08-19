# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `908f662ede36c72228de2cbba6e842e9b8c38b37`
- Runtime source hash: `28bf3e02ed450eb28276296fc134c1bf781843e12eea2797ebe067238a267260`
- Matching release tag: `runtime-908f662ede36c72228de2cbba6e842e9b8c38b37`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
