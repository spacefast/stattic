# Source provenance

This branch is an automated, allowlisted source snapshot of the Spacefast runtime.

- Source repository: `spacefast/monorepo`
- Source revision: `b9cd1add779046b5d19db26991fe589836824042`
- Runtime source hash: `e8ab05c3f6823c889172e33b89acbc5b03d5510562190dd367e2de5590249e1e`
- Matching release tag: `runtime-b9cd1add779046b5d19db26991fe589836824042`

The private monorepo is the development authority. This public repository is a
one-way source and release mirror; changes made here are replaced by the next
sync. GitHub Actions are intentionally disabled for this repository, and the
snapshot contains no workflow files.

The snapshot includes the allowlisted Rust runtime crates, PHP engine, runtime
tests, and selected runtime build scripts. It excludes the Spacefast control
plane, dashboards, infrastructure configuration, credentials, and build outputs.
