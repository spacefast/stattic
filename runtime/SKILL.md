---
name: spacefast-runtime-operator
description: "Install, configure, and operate a self-hosted Spacefast Engine — the open-source PHP runtime. Use for local serving, management-token setup, version uploads, activation, repair, and runtime troubleshooting."
---

# Operating Spacefast Engine

You are operating Spacefast Engine, the open-source PHP runtime (this directory). Read
`runtime/README.md` first for the full contract. Pre-1.0: contracts are unstable.

## Quick Start (local serving)

1. Verify PHP: `php -v` (8.5.x only), extensions `sodium`, `curl`, `zip`.
2. Lay out a web root:
   - build or obtain the engine ZIP
   - run `runtime/installer.php <zip>` with the expected revision and digests
   - the installer creates `.stattic/releases/<release>/`, `.stattic/storage`, and the
     public loader aliases, then atomically publishes `.stattic/active-release`
3. Configure via environment variables:

```bash
export SPACEFAST_RUNTIME_INSTANCE_ID=local-dev
export SPACEFAST_RUNTIME_JWKS_PATH=/absolute/path/to/jwks.json
```

4. Run: `php -S 127.0.0.1:8080 <webroot>/index.php`
5. Probe: `curl -s http://127.0.0.1:8080/__spacefast/health.php` — public-safe, returns
   schema/engine version and `site_state`.

Public serving never calls the Spacefast API. Only management-token verification needs a
JWKS (self-hosted local file via `SPACEFAST_RUNTIME_JWKS_PATH`, WP.Cloud inline base64 via
`SPACEFAST_RUNTIME_JWKS_B64`, or fetched from
`<SPACEFAST_API_BASE_URL>/.well-known/spacefast-runtime-jwks.json`).

## Management Calls

All management/upload requests use the site's primary hostname and a Bearer JWT
signed with an Ed25519 key from the JWKS. Management claims:
`aud=stattic-runtime-management`, `runtime_instance_id`,
`operation_id`, `action`, `exp`, `nbf`, `jti`, plus scope (`space_id`, `version_id`,
`route_name`). One token per action; `jti` is replay-protected.

Deploy flow:

1. `POST /__spacefast/api.php?route=/spaces/{spaceId}/versions` -> returns `upload_id` for a
   declared file manifest. `sha256` is optional.
2. Upload bytes with the session-scoped upload JWT
   (`aud=stattic-runtime-upload`, `deploy_session_id=upload_id`,
   manifest scope matching the session):
   - local bytes with `sha256`: `PUT /__spacefast/upload.php?route=/spaces/{spaceId}/blobs/{sha256}`
   - local bytes without `sha256`: `PUT /__spacefast/upload.php?op=file&upload_id={uploadId}&path={path}`;
     the runtime streams, hashes, and binds the bytes to the declared path
   - URL-sourced files: `POST /__spacefast/upload.php?op=fetch&upload_id={uploadId}&path={path}`
     with `{"url": "https://..."}` — the runtime fetches the bytes itself
     Every uploaded path must appear in the manifest and match its declared bytes.
3. `POST .../versions/{versionId}/finalize` with `upload_id` and optional `activate`
   (`route_name`, `production_hostnames`, `version_hostnames`, `config`; access
   admission is the single Grant projection in `config.authorization`; owner
   access has no separate deny/firewall lane).
4. Point a route later: `PUT .../routes/{routeName}` with `version_id` + hostnames.

## Troubleshooting

- `runtime_api_not_found` on management calls → unsupported route or action.
- `runtime_instance_mismatch` → token `runtime_instance_id` does not match config.
- `runtime_jwks_key_missing` / `runtime_jwks_fetch_failed` → provision
  `SPACEFAST_RUNTIME_JWKS_PATH` for self-hosted, `SPACEFAST_RUNTIME_JWKS_B64`
  for WP.Cloud, or fix `SPACEFAST_API_BASE_URL`.
- 503 `undeployed` on public hosts → no route generation yet; point a route at a
  finalized version.
- Serving issues after manual storage surgery → `POST .../spaces/{spaceId}/repair`
  rebuilds the route index.
- Inspect the journal at `.stattic/storage/runtime/journal.jsonl`.

External PRs are welcome — this runtime is the open, portable half of Spacefast.
