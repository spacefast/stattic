<?php
declare(strict_types=1);

// This public route runs outside the management/tier paths that normally
// materialize WP.Cloud's Atomic persistent data. Load it here so the bootstrap
// can resolve the canonical control-plane URL without baking another copy into
// every version's serving config.
require_once __DIR__ . '/../shared/bootstrap-config.php';

function _stattic_serve_spacefast_sdk(array $serving, string $requestHost, string $requestMethod): void
{
    _stattic_send_spacefast_sdk_cors_headers();
    if ($requestMethod === 'OPTIONS') {
        http_response_code(204);
        header('Cache-Control: no-store', false);
        exit;
    }
    if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
        http_response_code(405);
        header('Allow: GET, HEAD, OPTIONS', false);
        header('Content-Type: application/javascript; charset=utf-8', false);
        echo "window.Spacefast=window.Spacefast||{error:'method_not_allowed'};";
        exit;
    }

    $previewToken = _stattic_spacefast_sdk_preview_token();
    $body = _stattic_spacefast_sdk_bootstrap($serving, $requestHost, $previewToken);
    $revision = _stattic_spacefast_sdk_revision($body);
    $etag = '"' . $revision . '"';

    http_response_code(200);
    header('Content-Type: application/javascript; charset=utf-8', false);
    header('Cache-Control: ' . STATTIC_DEFAULT_EDGE_CACHE_CONTROL, false);
    header('ETag: ' . $etag, false);
    header('X-Spacefast-Sdk-Revision: ' . $revision, false);
    if ($previewToken === null && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    if ($requestMethod !== 'HEAD') {
        echo $body;
    }
    exit;
}

function _stattic_spacefast_sdk_bootstrap(array $serving, string $requestHost, ?string $previewToken = null): string
{
    $sdkConfig = _stattic_spacefast_sdk_config($serving);
    $manifest = [
        'version' => 3,
        'environment' => $previewToken !== null ? 'preview' : 'production',
        'host' => _stattic_normalize_hostname($requestHost),
        'spaceId' => is_string($serving['space_id'] ?? null) ? $serving['space_id'] : null,
        'versionId' => is_string($serving['version_id'] ?? null) ? $serving['version_id'] : null,
        'apiBase' => _stattic_spacefast_sdk_api_base_url($sdkConfig),
        'cast' => [
            'apiBase' => _stattic_spacefast_sdk_base_url($sdkConfig),
            'wsPath' => _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_ws_url'),
            'resourceKey' => _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_resource_key'),
        ],
    ];
    return '(function(){' .
        'var manifest=' . json_encode($manifest, JSON_UNESCAPED_SLASHES) . ';' .
        'var previewToken=' . json_encode($previewToken, JSON_UNESCAPED_SLASHES) . ';' .
        'var root=window.Spacefast=window.Spacefast||{};' .
        'root.manifest=manifest;' .
        'if(!manifest.apiBase||!manifest.spaceId)return;' .
        'if(!root.tagLoader){' .
        'var tagUrl=new URL(manifest.apiBase.replace(/\\/+$/,"")+"/v1/spaces/"+encodeURIComponent(manifest.spaceId)+"/tags/sdk.js");' .
        'tagUrl.searchParams.set("host",manifest.host||location.host);' .
        'if(previewToken)tagUrl.searchParams.set("preview",previewToken);' .
        'var t=document.createElement("script");' .
        't.async=true;t.src=tagUrl.toString();t.dataset.spacefastSdk="v1";' .
        'root.tagLoader=t;document.head.appendChild(t);' .
        '}' .
        'if(root.collabLoader||!manifest.cast||!manifest.cast.apiBase||!manifest.cast.resourceKey)return;' .
        'var o=document.createElement("script");' .
        'o.async=true;o.type="module";' .
        'o.src=manifest.cast.apiBase.replace(/\\/+$/,"")+"/sdk/v1/collab.js";' .
        'o.onerror=function(){var e=new Error("Spacefast Comments module failed to load");console.error(e);window.dispatchEvent(new CustomEvent("spacefast:collab-error",{detail:{stage:"module",message:e.message}}));};' .
        'root.collabLoader=o;' .
        'document.head.appendChild(o);' .
        '})();';
}

function _stattic_spacefast_sdk_api_base_url(array $sdkConfig): ?string
{
    $base = rtrim(_stattic_config_value('SPACEFAST_API_BASE_URL'), '/');
    if ($base !== '' && filter_var($base, FILTER_VALIDATE_URL)) {
        return $base;
    }

    $castBase = _stattic_spacefast_sdk_base_url($sdkConfig);
    $derived = is_string($castBase)
        ? preg_replace('~^(https?://)cast(?=\.)~i', '$1api', $castBase)
        : null;
    return is_string($derived) && $derived !== $castBase && filter_var($derived, FILTER_VALIDATE_URL)
        ? rtrim($derived, '/')
        : null;
}

function _stattic_spacefast_sdk_config_string(array $config, string $key): ?string
{
    $value = $config[$key] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function _stattic_spacefast_sdk_base_url(array $sdkConfig): ?string
{
    $base = _stattic_spacefast_sdk_config_string($sdkConfig, 'cast_api_base')
        ?? _stattic_config_value('SPACEFAST_CAST_API_URL');
    $base = rtrim($base, '/');
    return $base !== '' ? $base : null;
}

function _stattic_spacefast_sdk_config(array $serving): array
{
    $versionId = is_string($serving['version_id'] ?? null) ? $serving['version_id'] : '';
    $versions = is_array($serving['versions'] ?? null) ? $serving['versions'] : [];
    $version = $versionId !== '' && is_array($versions[$versionId] ?? null) ? $versions[$versionId] : [];
    $config = is_array($version['serving_config'] ?? null) ? $version['serving_config'] : [];
    return is_array($config['spacefast_sdk'] ?? null) ? $config['spacefast_sdk'] : [];
}

function _stattic_spacefast_sdk_revision(string $body): string
{
    return 'runtime:' . hash('sha256', $body);
}

function _stattic_spacefast_sdk_preview_token(): ?string
{
    if (isset($_GET['preview']) && is_string($_GET['preview']) && trim($_GET['preview']) !== '') {
        return trim($_GET['preview']);
    }
    return null;
}

function _stattic_send_spacefast_sdk_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *', false);
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS', false);
    header('Access-Control-Allow-Headers: If-None-Match', false);
    header('Cross-Origin-Resource-Policy: cross-origin', false);
    header('Timing-Allow-Origin: *', false);
}
