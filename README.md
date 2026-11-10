# Stattic runtime source

This repository mirrors the source corresponding to Spacefast's published
Stattic runtime engine. The current snapshot comes from monorepo revision
`74d20f875ee020a459e0c22c08168169f8d7633c`.

Release tags use `runtime-<monorepo revision>` and point at the public source
commit used for that release. The attached `runtime-engine.zip` is the
installable wp.cloud engine; it contains the PHP runtime and one multicall
native executable, `bin/stattic-runtime`.

See [SOURCE_PROVENANCE.md](SOURCE_PROVENANCE.md) for the exact source identity
and mirror boundary.
