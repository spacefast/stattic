# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `905144461a91da1e40a47b7cc9f9302c8a5a7b13`
- Runtime source hash: `2fa92be90c6b7608af9d34bef5df94f9823d9ec787844635536b3ecf1e6d777a`
- Matching release tag: `runtime-905144461a91da1e40a47b7cc9f9302c8a5a7b13`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
