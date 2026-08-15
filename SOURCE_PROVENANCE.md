# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b08af1f8847bc6cb882c057756a27c6a7a09447a`
- Runtime source hash: `dfa1630d8777a6d6a62702141a24dee161eeca366dfe80a69cba3bc5300d61dc`
- Matching release tag: `runtime-b08af1f8847bc6cb882c057756a27c6a7a09447a`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
