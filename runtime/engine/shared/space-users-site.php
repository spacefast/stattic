<?php
declare(strict_types=1);

// Space Users on the site tables, read before WordPress loads.

/**
 * The table prefix and the `auth` salt halves from the site's wp-config.php,
 * read without executing it (it ends by booting WordPress). Null when the file
 * is unreadable; empty salt halves fall back to the options table, the way
 * wp_salt() does. The prefix is a SQL identifier fragment, so it is allowlisted.
 *
 * @return array{prefix:string,key:string,salt:string}|null
 */
function _stattic_space_users_site_config(string $publicRoot): ?array
{
    // Where WordPress itself looks: beside wp-load.php (a box links it from
    // core), else one directory above it.
    $core = realpath($publicRoot . '/wp-load.php');
    $file = null;
    foreach ([$publicRoot, $core === false ? null : dirname($core), $core === false ? null : dirname($core, 2)] as $dir) {
        if (is_string($dir) && is_file($dir . '/wp-config.php')) { $file = $dir . '/wp-config.php'; break; }
    }
    $source = $file === null ? false : file_get_contents($file);
    if (!is_string($source)) return null;
    $define = static function (string $name) use ($source): string {
        if (preg_match('/define\s*\(\s*([\'"])' . $name . '\1\s*,\s*([\'"])((?:\\\\.|(?!\2).)*)\2\s*\)/s', $source, $match) !== 1) return '';
        return stripslashes($match[3]);
    };
    $prefix = preg_match('/\$table_prefix\s*=\s*([\'"])([A-Za-z0-9_]+)\1\s*;/', $source, $match) === 1 ? $match[2] : 'wp_';
    return [
        'prefix' => $prefix, 'key' => $define('AUTH_KEY'), 'salt' => $define('AUTH_SALT'),
        'db' => ['DB_HOST' => $define('DB_HOST'), 'DB_NAME' => $define('DB_NAME'), 'DB_USER' => $define('DB_USER'), 'DB_PASSWORD' => $define('DB_PASSWORD')],
    ];
}

/**
 * The site database URL: the engine's provider resolver, or, before anything
 * defined the DB_* tuple (a WP-CLI `--exec` runs ahead of wp-config.php), the
 * same tuple read out of wp-config.php.
 */
function _stattic_space_users_site_database_url(array $site): string
{
    require_once __DIR__ . '/artifacts.php';
    $url = _stattic_zero_runner_base_env()['SPACEFAST_ZERO_DATABASE_URL'] ?? '';
    if (is_string($url) && $url !== '') return $url;
    $db = $site['db'] ?? [];
    if (($db['DB_NAME'] ?? '') === '' || ($db['DB_USER'] ?? '') === '') return '';
    $host = ($db['DB_HOST'] ?? '') === '' ? '127.0.0.1' : $db['DB_HOST'];
    if (preg_match('/^(?:\[[0-9A-Fa-f:]+\]|[A-Za-z0-9._-]+)(?::[1-9][0-9]{0,4})?$/', $host) !== 1) return '';
    return 'mysql://' . rawurlencode($db['DB_USER']) . ':' . rawurlencode($db['DB_PASSWORD'] ?? '') . '@' . $host . '/' . rawurlencode($db['DB_NAME']);
}

/**
 * Whether this Space still has app accounts on the site tables
 * (`_spacefast_app_user`). A Zero v2 Space keeps its users in its own tables,
 * and nothing has moved these yet, so while any exist its Zero app runs
 * without Users: visitor sign-in on its own tables would make a second account
 * store beside them. The dashboard keeps managing them where they are. Answered
 * once per request (one indexed lookup); null when it can't be answered.
 */
function _stattic_space_users_site_accounts(string $privateRoot, string $spaceId): ?bool
{
    static $answers = [];
    $key = $privateRoot . "\0" . $spaceId;
    if (array_key_exists($key, $answers)) return $answers[$key];
    require_once __DIR__ . '/db-broker.php';
    $site = _stattic_space_users_site_config(dirname(dirname($privateRoot)));
    $url = $site === null ? '' : _stattic_space_users_site_database_url($site);
    if ($url === '') return $answers[$key] = null;
    $state = &_stattic_db_broker_state();
    [$previousUrl, $previousSource] = [$state['url'], $state['source']];
    _stattic_db_broker_bind($url, 'provider');
    $link = _stattic_db_broker_connection();
    $found = null;
    if ($link instanceof mysqli) {
        $statement = $link->prepare("SELECT 1 FROM {$site['prefix']}usermeta WHERE meta_key = '_spacefast_app_user' AND meta_value = ? LIMIT 1");
        if ($statement instanceof mysqli_stmt && $statement->bind_param('s', $spaceId) && $statement->execute()) {
            $result = $statement->get_result();
            $found = $result instanceof mysqli_result ? ($result->fetch_row() !== null) : null;
            $statement->close();
        } else {
            // 1146: no usermeta table, so no site accounts either.
            $found = $link->errno === 1146 ? false : null;
        }
    }
    _stattic_db_broker_bind($previousUrl, $previousSource);
    return $answers[$key] = $found;
}

/** Visitor sign-in on a Space whose accounts are still on the site tables answers this. */
function _stattic_space_users_refuse_site_accounts(string $privateRoot, string $spaceId): void
{
    require_once __DIR__ . '/response.php';
    $found = _stattic_space_users_site_accounts($privateRoot, $spaceId);
    if ($found === null) {
        _stattic_problem_refused(503, 'space_users_unavailable', 'Users is not ready on this Space.');
    }
    if ($found) {
        _stattic_problem_refused(409, 'space_users_site_accounts_present', 'This Space has app accounts from before its Zero app, and they have not moved yet. Sign-in is paused so none of them is lost.');
    }
}
