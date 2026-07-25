---
name: stattic-runtime-operator
description: Install, configure, and operate a self-hosted Spacefast Engine — the open-source PHP runtime. Serve exported spaces locally, run management/upload APIs, and migrate spaces in or out.
---

# Operating Spacefast Engine

You are operating Spacefast Engine, the open-source PHP runtime (this directory). Read
`runtime/README.md` first for the full contract. Pre-1.0: contracts are unstable.

## Quick Start (local serving)

1. Verify PHP: `php -v` (8.2+), extensions `sodium`, `curl`, `zip`.
2. Lay out a web root:
   - copy `runtime/engine/` → `<webroot>/.stattic/engine/`
   - copy `runtime/engine-manifest.json` → `<webroot>/.stattic/engine-manifest.json`
   - copy `runtime/entrypoint-shim.php` → `<webroot>/index.php` and
     `<webroot>/custom-redirects.php`
   - `mkdir -p <webroot>/.stattic/storage`
3. Configure via environment variables:

```bash
export SPACEFAST_MANAGEMENT_HOSTNAME=manage.localhost
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

All management/upload requests must use the management hostname
(`Host: $SPACEFAST_MANAGEMENT_HOSTNAME`) and a Bearer JWT signed with an Ed25519 key from
the JWKS. Management claims: `aud=stattic-runtime-management`, `runtime_instance_id`,
`operation_id`, `action`, `exp`, `nbf`, `jti`, plus scope (`space_id`, `version_id`,
`route_name`). One token per action; `jti` is replay-protected.

Deploy flow:

1. `POST /__spacefast/api.php?route=/spaces/{spaceId}/versions` -> returns `upload_id`. Either
   declare a file manifest (`files: [{path, size, sha256?}]`) or send
   `session_mode: "open"` with no manifest (optional `max_total_bytes` /
   `max_file_count` caps; runtime fail-safe defaults apply when absent).
2. Upload bytes with the session-scoped upload JWT
   (`aud=stattic-runtime-upload`, `deploy_session_id=upload_id`,
   `session_mode="declared"|"open"` matching the session):
   - small files: `PUT /__spacefast/upload.php?op=file&upload_id={uploadId}&path={path}`
   - large files: `PUT ...&part_number={n}` (1-based, contiguous) then
     `POST ...&complete=1`
   - many small files: `POST /__spacefast/upload.php?op=batch&upload_id={uploadId}` with a tar body
   - URL-sourced files: `POST /__spacefast/upload.php?op=fetch&upload_id={uploadId}&path={path}`
     with `{"url": "https://..."}` — the runtime fetches the bytes itself
     Declared sessions require every path to be in the manifest; open sessions accept any
     policy-valid path within the session byte/count caps, and finalize derives the
     manifest from the uploaded files.
3. `POST .../versions/{versionId}/finalize` with `upload_id` and optional `activate`
   (`route_name`, `production_hostnames`, `version_hostnames`, `config`; access
   rules are `config.policy`, password verifier maps are `config.secrets`).
4. Point a route later: `PUT .../routes/{routeName}` with `version_id` + hostnames.

## Migrate A Space In (import)

1. `POST .../spaces/{spaceId}/imports` (optional `version_id_map` to mint new ids)
2. `PUT .../imports/{importId}/archive` with the export ZIP body
3. `POST .../imports/{importId}/step` repeatedly until `status: complete`
4. Activate with `PUT .../routes/production`

## Migrate A Space Out (export)

1. `POST .../spaces/{spaceId}/exports` (optional `version_ids`)
2. `POST .../exports/{exportId}/step` until `status: complete`
3. `GET .../exports/{exportId}/archive` → ZIP with `spacefast_export_v1/spacefast.json`,
   `space/access-policy.json` (when set), and committed version trees (per-version
   `manifest.json` plus `files/` and compiled artifacts). No ownership/billing/domain
   data is included.

## Troubleshooting

- `runtime_api_not_found` on management calls → wrong `Host` header; management routes
  exist only on the management hostname (the response body is deliberately generic).
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
