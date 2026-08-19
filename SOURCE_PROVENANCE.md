# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `6e3daa6fbf0df1680c88f37770224e05f2b9feed`
- Runtime source hash: `846895593aa3a3a4cb29581aa131440e835779f744682c66d1f2e3576d5bac6d`
- Matching release tag: `runtime-6e3daa6fbf0df1680c88f37770224e05f2b9feed`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
