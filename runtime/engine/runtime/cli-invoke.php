<?php
declare(strict_types=1);

/**
 * Running one request through the engine from the PHP CLI.
 *
 * The provider's crontab runs a shell command on the site's box, and that
 * command has to reach the SAME routing a visitor reaches: static entries,
 * PHP Functions, Zero, the Functions relay. A loopback HTTP kick would answer
 * that by racing the visitor pool for a worker, and a second router would answer
 * it by drifting. So neither: this file STAGES a request into the superglobals the
 * front door already reads and then calls `_sf_serve_fast()`, the exact call
 * engine/init.php makes. Everything downstream is unaware it is on the CLI.
 *
 * What the CLI SAPI changes, and what it does not:
 *   * `header()` is a silent no-op and `headers_list()` is empty, so response
 *     HEADERS are not observable. The receipt therefore carries the status, not
 *     the header map. The status IS observable: `http_response_code()`
 *     works under CLI. Exit code follows from it, which is the whole contract
 *     the provider's `site-cron-results` webhook keys on.
 *   * `_stattic_send_server_file()` already declines on any non-FPM SAPI, so
 *     the accel lane falls back to the ordinary PHP body path.
 *   * CLI exposes piped bytes through `php://stdin`, not `php://input`. The
 *     entrypoint stages stdin in the shared request-body override before the
 *     visitor lane runs. There is no --body flag: the shell already has one.
 *
 * Hardening is NOT relaxed anywhere. tenant-prelude.php CANNOT.C requires that
 * one request execute at most one space's tenant file, because `open_basedir`
 * cannot be widened once tightened and only php-fpm's per-request ini reset
 * un-pins it. A CLI process has no such reset, so this file runs exactly ONE
 * request and exits. Never loop it.
 */

require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/serve.php';

// Same shape as purge.php's result line, and for the same reason: a dispatched
// route prints whatever tenant code feels like printing, so the receipt is a
// sentinel-prefixed line the parent searches for rather than "the contents of
// stderr". Body bytes own stdout; the receipt owns stderr.
const STATTIC_RUNTIME_INVOKE_RESULT_SENTINEL = 'spacefast-invoke-result: ';

// Usage/config errors the caller can fix. Distinct from exit 1, which means the
// request ran and answered >= 400, the signal the provider webhook keys on.
const STATTIC_RUNTIME_INVOKE_EXIT_USAGE = 2;
const STATTIC_RUNTIME_INVOKE_EXIT_UNRESOLVED = 3;
const STATTIC_RUNTIME_INVOKE_EXIT_FAILED = 1;

function _stattic_cli_fail(string $message, int $code = STATTIC_RUNTIME_INVOKE_EXIT_USAGE): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * `--name=value` flags, with `--header` repeating. Deliberately not getopt():
 * getopt() drops repeats of a long option, and the header list is the one flag
 * that has to repeat.
 *
 * @param list<string> $argv
 * @return array{flags: array<string,string>, headers: list<string>}
 */
function _stattic_cli_flags(array $argv): array
{
    $flags = [];
    $headers = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--')) {
            _stattic_cli_fail('unexpected argument: ' . (is_string($argument) ? $argument : ''));
        }
        $separator = strpos($argument, '=');
        if ($separator === false) {
            _stattic_cli_fail('flags take the --name=value form: ' . $argument);
        }
        $name = substr($argument, 2, $separator - 2);
        $value = substr($argument, $separator + 1);
        if ($name === 'header') {
            $headers[] = $value;
            continue;
        }
        $flags[$name] = $value;
    }
    return ['flags' => $flags, 'headers' => $headers];
}

/**
 * `name: value` pairs into the FastCGI request-parameter spelling the engine
 * reads everywhere ($_SERVER, never the process environment: the tenant scrub
 * walks the environment, so a request header staged here is not something it
 * can or should remove).
 *
 * @param list<string> $raw
 * @return array<string,string>
 */
function _stattic_cli_request_params(array $raw): array
{
    $params = [];
    foreach ($raw as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            _stattic_cli_fail('headers take the --header="name: value" form: ' . $line);
        }
        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        // The token grammar of RFC 9110 §5.1, and no control character in the
        // value: a staged header must not be able to forge a second one.
        if (
            preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name) !== 1
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            _stattic_cli_fail('header is not a valid field line: ' . $line);
        }
        $params[_stattic_cli_request_param_name($name)] = $value;
    }
    return $params;
}

// content-type and content-length are request params without the HTTP_ prefix
// (CGI/1.1 §4.1), the same spelling php-fpm hands the engine and the one
// functions-dispatch.php and the body readers look for.
function _stattic_cli_request_param_name(string $name): string
{
    $upper = strtoupper(str_replace('-', '_', $name));
    return $upper === 'CONTENT_TYPE' || $upper === 'CONTENT_LENGTH' ? $upper : 'HTTP_' . $upper;
}

/**
 * Stage the request and hand it to the visitor lane. Never returns: every
 * terminal below exits, and the receipt rides the shutdown sequence.
 *
 * $platformParams are the request params only the ENGINE may assert. They are
 * re-applied after _stattic_strip_untrusted_edge_headers() has removed whatever
 * a client sent under the same names, so their presence downstream is proof the
 * engine synthesized the request.
 *
 * @param array<string,string> $params
 * @param array<string,string> $platformParams
 */
function _stattic_cli_invoke(
    string $privateRoot,
    string $requestHost,
    string $requestMethod,
    string $requestTarget,
    array $params = [],
    array $platformParams = []
): never {
    // Split exactly as engine/init.php splits it: REQUEST_URI stays raw, the
    // canonical path is derived from it, and an uncanonicalizable path is
    // refused here rather than becoming the front door's 403 page on stdout.
    $path = _stattic_canonical_request_path(_stattic_request_uri_path($requestTarget));
    if ($path === null) {
        _stattic_cli_fail('request path is invalid: ' . $requestTarget);
    }
    $query = (string) (parse_url($requestTarget, PHP_URL_QUERY) ?? '');

    // Everything php-fpm would have filled in. $_GET too: the CGI layer
    // populates it from QUERY_STRING, and the serve path reads it directly
    // (the preview and tag-preview selectors, the access token redemption).
    $_SERVER['REQUEST_METHOD'] = $requestMethod;
    $_SERVER['REQUEST_URI'] = $requestTarget;
    $_SERVER['QUERY_STRING'] = $query;
    $_SERVER['HTTP_HOST'] = $requestHost;
    $_SERVER['SERVER_NAME'] = $requestHost;
    foreach ($params as $name => $value) {
        $_SERVER[$name] = $value;
    }
    parse_str($query, $_GET);
    _stattic_platform_asserted_request_params($platformParams);

    $body = stream_get_contents(STDIN);
    if (!is_string($body)) {
        _stattic_cli_fail('request body could not be read from stdin');
    }
    _stattic_request_body_override($body);
    if (!isset($_SERVER['CONTENT_LENGTH'])) {
        $_SERVER['CONTENT_LENGTH'] = (string) strlen($body);
    }

    _stattic_cli_arm_receipt(['method' => $requestMethod, 'path' => $path]);

    _sf_serve_fast(
        $privateRoot,
        $requestMethod,
        $requestTarget,
        $path,
        _stattic_normalize_hostname($requestHost)
    );
}

/**
 * Arm the receipt, before the dispatch that will exit.
 *
 * Two hops on purpose. Registering here puts this FIRST in the shutdown
 * sequence, ahead of the cleanup the serve lanes register DURING the request
 * (php-functions.php's scratch tmp, context.php's deferred-work queue), and
 * the receipt has to exit(), which would skip all of it. So the first hop
 * captures the terminal status before cleanup can replace error_get_last(),
 * then re-queues: a shutdown function registered from inside the shutdown
 * sequence is appended, so the second hop runs after everything the request
 * registered.
 *
 * @param array{method: string, path: string} $request
 */
function _stattic_cli_arm_receipt(array $request): void
{
    _stattic_cli_capture_fatal_errors();
    register_shutdown_function(static function () use ($request): void {
        // A cleanup warning must not hide the fatal that ended the handler.
        $error = error_get_last();
        $fatal = _stattic_cli_observed_fatal()
            || (is_array($error)
                && in_array($error['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true));
        $code = http_response_code();
        $status = $fatal ? 500 : (is_int($code) && $code > 0 ? $code : 200);

        register_shutdown_function('_stattic_cli_receipt', $request, $status);
    });
}

// Catchable fatal errors have to be observed when PHP raises them. A prepend
// file can register shutdown work before this entrypoint loads, and a warning
// from that earlier callback replaces error_get_last() before our own shutdown
// callback runs. Uncatchable engine fatals still use the shutdown fallback.
function _stattic_cli_capture_fatal_errors(): void
{
    $previous = null;
    $previous = set_error_handler(
        static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
            $handled = $previous !== null && $previous($severity, $message, $file, $line) === true;
            if (!$handled) {
                _stattic_cli_observed_fatal(true);
            }
            return $handled;
        },
        E_USER_ERROR | E_RECOVERABLE_ERROR
    );
}

function _stattic_cli_observed_fatal(?bool $observed = null): bool
{
    static $fatal = false;
    if ($observed !== null) {
        $fatal = $observed;
    }
    return $fatal;
}

/**
 * The receipt, and the process exit code with it.
 *
 * @param array{method: string, path: string} $request
 */
function _stattic_cli_receipt(array $request, int $status): never
{
    $receipt = json_encode([
        'method' => $request['method'],
        'path' => $request['path'],
        'status' => $status,
    ], JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, STATTIC_RUNTIME_INVOKE_RESULT_SENTINEL . (is_string($receipt) ? $receipt : '{}') . "\n");

    // The one signal the provider's cron-results webhook reads.
    exit($status < 400 ? 0 : 1);
}

/**
 * The private root for the release this file belongs to, or a caller's override.
 * The deployed layout is <siteRoot>/.stattic/storage, and the release lives at
 * <siteRoot>/.stattic/releases/<release>/engine, so the engine's own location
 * already names it and the crontab entry does not have to.
 */
function _stattic_cli_private_root(string $engineRoot, string $override): string
{
    $root = $override !== '' ? $override : _stattic_runtime_install_root($engineRoot) . '/storage';
    if (!is_dir($root)) {
        _stattic_cli_fail('runtime storage is not provisioned: ' . $root, STATTIC_RUNTIME_INVOKE_EXIT_UNRESOLVED);
    }
    return $root;
}
