<?php
/** Load only system CLI modules, without reading site or provider PHP ini. */
function spacefast_qualification_cli_ready(): bool
{
    if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600 || php_ini_loaded_file() !== false) return false;
    foreach (['curl', 'zip', 'tokenizer'] as $extension) {
        if (extension_loaded($extension)) continue;
        if (!function_exists('dl') || !@dl($extension . '.' . PHP_SHLIB_SUFFIX)) return false;
    }
    return class_exists('ZipArchive') && function_exists('curl_init') && function_exists('flock') && function_exists('token_get_all') && function_exists('exec');
}

/** Parse bootstrap config as data. No include, eval, or provider/plugin bootstrap. */
function spacefast_config_data(string $bytes): array
{
    if (strlen($bytes) > 1048576) throw new RuntimeException('qualification_config_too_large');
    $tokens = array_values(array_filter(token_get_all($bytes), static fn ($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $at = 0;
    $take = static function ($wanted) use (&$tokens, &$at): void {
        $token = $tokens[$at++] ?? null;
        if (is_int($wanted) ? (!is_array($token) || $token[0] !== $wanted) : $token !== $wanted) throw new RuntimeException('qualification_config_not_data');
    };
    $literal = static function () use (&$tokens, &$at): string {
        $token = $tokens[$at++] ?? null;
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING || $token[1][0] !== "'") throw new RuntimeException('qualification_config_not_data');
        $body = substr($token[1], 1, -1);
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
    };
    $take(T_OPEN_TAG);
    $take(T_RETURN);
    $take('[');
    $data = [];
    while (($tokens[$at] ?? null) !== ']') {
        $key = $literal();
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) || array_key_exists($key, $data)) throw new RuntimeException('qualification_config_key_invalid');
        $take(T_DOUBLE_ARROW);
        $data[$key] = $literal();
        $take(',');
    }
    $take(']');
    $take(';');
    if ($at !== count($tokens)) throw new RuntimeException('qualification_config_not_data');
    return $data;
}
