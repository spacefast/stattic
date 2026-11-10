<?php
declare(strict_types=1);

const STATTIC_SPACE_MAIL_ENDPOINT_PATH = '/__spacefast/mail.php';
const STATTIC_SPACE_MAIL_GRANT_SERVER_VAR = 'HTTP_X_SF_MAIL_GRANT';
const STATTIC_SPACE_MAIL_GRANT_TTL_SECONDS = 300;
const STATTIC_SPACE_MAIL_REQUEST_MAX_BYTES = 1048576;
// Caps each SMTP wait, not the whole send, below the broker's 15s timeout. Once
// the broker gives up it frees its send slot, so a hung relay must not keep
// this FPM worker for PHPMailer's default 300s per wait.
const STATTIC_SPACE_MAIL_SMTP_TIMEOUT_SECONDS = 10;

function _stattic_space_mail_address(mixed $value): ?string
{
    if (!is_array($value) || !is_string($value['email'] ?? null)) {
        return null;
    }
    $email = trim($value['email']);
    // WordPress splits address headers on commas, including quoted local parts.
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, ',')) {
        return null;
    }
    if (!array_key_exists('name', $value)) {
        return $email;
    }
    if (!is_string($value['name']) || $value['name'] === '' || preg_match('/[\r\n\0]/', $value['name']) === 1 || preg_match('//u', $value['name']) !== 1) {
        return null;
    }
    // WordPress splits Cc/Bcc/Reply-To on commas even inside quoted names.
    // Keep encoded-words below RFC 2047's 75-character limit, without splitting UTF-8.
    $name = $value['name'];
    $words = [];
    $length = strlen($name);
    for ($offset = 0; $offset < $length; $offset = $end) {
        $end = min($offset + 45, $length);
        while ($end < $length && (ord($name[$end]) & 0xC0) === 0x80) {
            $end--;
        }
        $words[] = '=?UTF-8?B?' . base64_encode(substr($name, $offset, $end - $offset)) . '?=';
    }
    return implode(' ', $words) . ' <' . $email . '>';
}

/** @return list<string>|null */
function _stattic_space_mail_addresses(mixed $value, bool $allowEmpty): ?array
{
    if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && count($value) === 0)) {
        return null;
    }
    $addresses = [];
    foreach ($value as $address) {
        $formatted = _stattic_space_mail_address($address);
        if ($formatted === null) {
            return null;
        }
        $addresses[] = $formatted;
    }
    return $addresses;
}

/**
 * Map a normalized email message to wp_mail() arguments.
 * Invalid addresses or headers return null before reaching the transport.
 *
 * @return array{to:list<string>,subject:string,message:string,headers:list<string>,alt_body:?string,recipients:int}|null
 */
function _stattic_space_mail_arguments(array $payload): ?array
{
    $from = _stattic_space_mail_address($payload['from'] ?? null);
    $to = _stattic_space_mail_addresses($payload['to'] ?? null, false);
    $cc = array_key_exists('cc', $payload)
        ? _stattic_space_mail_addresses($payload['cc'], true)
        : [];
    $bcc = array_key_exists('bcc', $payload)
        ? _stattic_space_mail_addresses($payload['bcc'], true)
        : [];
    $replyTo = array_key_exists('replyTo', $payload)
        ? _stattic_space_mail_address($payload['replyTo'])
        : $from;
    $subject = is_string($payload['subject'] ?? null) ? $payload['subject'] : '';
    if (
        $from === null || $to === null || $cc === null || $bcc === null || $replyTo === null
        || $subject === '' || preg_match('/[\r\n\0]/', $subject) === 1
    ) {
        return null;
    }

    $text = is_string($payload['text'] ?? null) ? $payload['text'] : null;
    $html = is_string($payload['html'] ?? null) ? $payload['html'] : null;
    if ($html !== null) {
        $message = $html;
        $altBody = $text;
        $contentType = 'text/html; charset=UTF-8';
    } elseif ($text !== null) {
        $message = $text;
        $altBody = null;
        $contentType = null;
    } else {
        return null;
    }

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
    ];
    if ($cc !== []) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    if ($bcc !== []) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
    }
    if ($contentType !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $reserved = ['from', 'to', 'cc', 'bcc', 'reply-to', 'content-type', 'message-id'];
    $custom = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
    foreach ($custom as $name => $value) {
        if (
            !is_string($name) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1
            || in_array(strtolower($name), $reserved, true)
            || !is_string($value) || preg_match('/[\r\n\0]/', $value) === 1
        ) {
            return null;
        }
        $headers[] = $name . ': ' . $value;
    }

    return [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
        'alt_body' => $altBody,
        'recipients' => count($to) + count($cc) + count($bcc),
        'from_named' => is_array($payload['from'] ?? null) && array_key_exists('name', $payload['from']),
    ];
}

/**
 * A grant lets the service broker send for one Space. Only the runtime can mint
 * one: the key never leaves this box's private root, and tenant code never sees
 * the broker's environment. The claims are the allowance the route granted when
 * the handler started.
 */
function _stattic_space_mail_mint_grant(string $privateRoot, string $spaceId, string $senderDomain, int $limit): ?string
{
    require_once __DIR__ . '/storage.php';
    $key = _stattic_lazy_minted_secret($privateRoot, 'space-mail-key', 32);
    if (!is_string($key) || $spaceId === '' || $senderDomain === '' || $limit <= 0) {
        return null;
    }
    $encoded = _stattic_base64url_encode((string) json_encode([
        'space_id' => $spaceId,
        'sender_domain' => $senderDomain,
        'limit' => $limit,
        'exp' => time() + STATTIC_SPACE_MAIL_GRANT_TTL_SECONDS,
    ]));
    return $encoded . '.' . hash_hmac('sha256', $encoded, $key);
}

/** @return array{space_id:string,sender_domain:string,limit:int,exp:int}|null */
function _stattic_space_mail_verify_grant(string $privateRoot, string $grant): ?array
{
    require_once __DIR__ . '/storage.php';
    $parts = explode('.', $grant);
    if (count($parts) !== 2) {
        return null;
    }
    $key = _stattic_lazy_minted_secret($privateRoot, 'space-mail-key', 32);
    if (!is_string($key) || !hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
        return null;
    }
    $claims = json_decode(_stattic_base64url_decode($parts[0]), true);
    if (
        !is_array($claims)
        || !is_string($claims['space_id'] ?? null) || $claims['space_id'] === ''
        || !is_string($claims['sender_domain'] ?? null) || $claims['sender_domain'] === ''
        || !is_int($claims['limit'] ?? null) || $claims['limit'] <= 0
        || !is_int($claims['exp'] ?? null) || $claims['exp'] < time()
    ) {
        return null;
    }
    return $claims;
}

/**
 * Atomic's mail plugin authenticates with the request Host, so set it to the
 * sending identity whichever hostname carried this request.
 */
function _stattic_space_mail_bootstrap(string $publicRoot, string $senderDomain): bool
{
    if (!is_file($publicRoot . '/wp-load.php')) {
        return false;
    }
    $_SERVER['HTTP_HOST'] = $senderDomain;
    $_SERVER['SERVER_NAME'] = $senderDomain;
    $_SERVER['HTTPS'] = 'on';
    require_once $publicRoot . '/wp-load.php';
    return function_exists('wp_mail');
}

function _stattic_space_mail_transport(array $mail, string $senderDomain): bool
{
    $configure = static function (mixed $mailer) use ($mail, $senderDomain): void {
        if (!is_object($mailer)) {
            return;
        }
        if (is_string($mail['alt_body'])) {
            $mailer->AltBody = $mail['alt_body'];
        }
        // WordPress would otherwise sign an unnamed message as "WordPress".
        if (!$mail['from_named']) {
            $mailer->FromName = $senderDomain;
        }
        // Timeout bounds the connect and each reply; Timelimit each multi-line read.
        $timeout = (int) ($mailer->Timeout ?? 0);
        if ($timeout <= 0 || $timeout > STATTIC_SPACE_MAIL_SMTP_TIMEOUT_SECONDS) {
            $mailer->Timeout = STATTIC_SPACE_MAIL_SMTP_TIMEOUT_SECONDS;
        }
        $smtp = class_exists('PHPMailer\\PHPMailer\\SMTP', false) && method_exists($mailer, 'getSMTPInstance')
            ? $mailer->getSMTPInstance()
            : null;
        if (is_object($smtp) && ((int) $smtp->Timelimit <= 0 || (int) $smtp->Timelimit > STATTIC_SPACE_MAIL_SMTP_TIMEOUT_SECONDS)) {
            $smtp->Timelimit = STATTIC_SPACE_MAIL_SMTP_TIMEOUT_SECONDS;
        }
    };
    // Late, so the provider's own transport settings are already in place.
    add_action('phpmailer_init', $configure, PHP_INT_MAX - 1);
    try {
        return wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers']);
    } finally {
        remove_action('phpmailer_init', $configure, PHP_INT_MAX - 1);
    }
}

/** @param array{space_id:string,sender_domain:string,limit:int,exp:int} $grant */
function _stattic_space_mail_send(array $payload, string $publicRoot, string $privateRoot, array $grant): bool
{
    $mail = _stattic_space_mail_arguments($payload);
    if ($mail === null) {
        return false;
    }
    $from = is_string($payload['from']['email'] ?? null) ? $payload['from']['email'] : '';
    $senderDomain = strtolower($grant['sender_domain']);
    if (strtolower(substr((string) strrchr($from, '@'), 1)) !== $senderDomain) {
        return false;
    }
    // Load WordPress first: a bootstrap that exits has reserved nothing, and no
    // lock is held while it runs.
    if (!_stattic_space_mail_bootstrap($publicRoot, $senderDomain)) {
        return false;
    }
    require_once __DIR__ . '/storage.php';
    require_once __DIR__ . '/context.php';
    $spaceId = $grant['space_id'];
    $limit = $grant['limit'];
    $recipients = $mail['recipients'];
    $root = _stattic_space_root($privateRoot, $spaceId);
    _stattic_runtime_mkdir($root);
    return _stattic_lock_with($root . '/email-usage.lock', STATTIC_LOCK_WAIT, static fn (): bool => false,
        static function () use ($root, $limit, $recipients, $mail, $senderDomain): bool {
            $path = $root . '/email-usage.json';
            $day = gmdate('Y-m-d');
            $previous = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $used = is_array($previous) && ($previous['day'] ?? null) === $day ? (int) ($previous['recipients'] ?? 0) : 0;
            if ($used + $recipients > $limit) {
                return false;
            }
            // Reserve before transport acceptance: an interrupted ambiguous
            // send consumes allowance. wp.cloud's email meter is the usage.
            _stattic_runtime_write_json_atomic($path, ['day' => $day, 'recipients' => $used + $recipients]);
            $accepted = _stattic_space_mail_transport($mail, $senderDomain);
            if (!$accepted) {
                _stattic_runtime_write_json_atomic($path, ['day' => $day, 'recipients' => $used]);
            }
            return $accepted;
        },
    );
}
