# spacefast-secret-scan

Linear-time, `wasm32-wasip1`-compatible Rust secret scanner that parses standard
[`gitleaks.toml`](https://github.com/gitleaks/gitleaks) configurations (MIT
licensed), applies tiered severity classification (`Block` vs `Warn`), and
emits only redacted previews and fingerprints — never raw secret bytes.

## Features

- `default-rules` (enabled by default): embeds the pinned upstream
  `gitleaks.toml` rule set (`v8.30.1`). Disable with
  `default-features = false` to supply your own TOML or programmatic rules.

## Refreshing upstream rules

```sh
node scripts/sync-gitleaks-rules.mjs --tag v8.30.1
cargo test -p spacefast-secret-scan
```

The test suite verifies that every vendored rule regex compiles cleanly under
Rust's linear-time `regex` engine and has an explicit tier assignment in
`src/tiers.rs`.
