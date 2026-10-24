<?php
declare(strict_types=1);

function _stattic_mail_address(mixed $value): ?string
{
    if (!is_array($value) || !is_string($value['email'] ?? null)) {
        return null;
    }
    $email = trim($value['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    if (!array_key_exists('name', $value)) {
        return $email;
    }
    if (!is_string($value['name']) || $value['name'] === '' || preg_match('/[\r\n\0]/', $value['name']) === 1) {
        return null;
    }
    return $value['name'] . ' <' . $email . '>';
}

/** @return list<string>|null */
function _stattic_mail_addresses(mixed $value, bool $allowEmpty): ?array
{
    if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && count($value) === 0)) {
        return null;
    }
    $addresses = [];
    foreach ($value as $address) {
        $formatted = _stattic_mail_address($address);
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
 * @return array{to:list<string>,subject:string,message:string,headers:list<string>,alt_body:?string}|null
 */
function _stattic_mail_arguments(array $payload): ?array
{
    $from = _stattic_mail_address($payload['from'] ?? null);
    $to = _stattic_mail_addresses($payload['to'] ?? null, false);
    $cc = array_key_exists('cc', $payload)
        ? _stattic_mail_addresses($payload['cc'], true)
        : [];
    $bcc = array_key_exists('bcc', $payload)
        ? _stattic_mail_addresses($payload['bcc'], true)
        : [];
    $replyTo = array_key_exists('replyTo', $payload)
        ? _stattic_mail_address($payload['replyTo'])
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
    ];
}

function _stattic_mail_send(array $payload, string $publicRoot): bool
{
    $mail = _stattic_mail_arguments($payload);
    if ($mail === null || !is_file($publicRoot . '/wp-load.php')) {
        return false;
    }
    require_once $publicRoot . '/wp-load.php';
    if (!function_exists('wp_mail')) {
        return false;
    }
    $configure = static function (mixed $mailer) use ($mail): void {
        if (is_string($mail['alt_body']) && is_object($mailer)) {
            $mailer->AltBody = $mail['alt_body'];
        }
    };
    add_action('phpmailer_init', $configure);
    try {
        return wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers']);
    } finally {
        remove_action('phpmailer_init', $configure);
    }
}

function _stattic_mail_send_metered(array $payload, string $publicRoot): bool
{
    require_once __DIR__ . '/retail-usage.php';
    require_once __DIR__ . '/context.php';
    $privateRoot = (string) getenv('SPACEFAST_SERVICE_USAGE_ROOT');
    $spaceId = (string) getenv('SPACEFAST_SERVICE_SPACE_ID');
    // Existing callers outside the Space service broker retain their own mail
    // policy. Platform notifications never enter this metering lane.
    if ($privateRoot === '' || $spaceId === '') {
        return _stattic_mail_send($payload, $publicRoot);
    }
    if (_stattic_mail_arguments($payload) === null) {
        return false;
    }
    $recipients = count($payload['to']) + count($payload['cc'] ?? []) + count($payload['bcc'] ?? []);
    // Route configs pushed before billing limits existed carry no limit.
    $configuredLimit = (int) getenv('SPACEFAST_SERVICE_EMAIL_DAILY_LIMIT');
    $limit = $configuredLimit > 0 ? $configuredLimit : PHP_INT_MAX;
    $root = _stattic_space_root($privateRoot, $spaceId);
    _stattic_runtime_mkdir($root);
    return _stattic_lock_with($root . '/email-usage.lock', STATTIC_LOCK_WAIT, static fn (): bool => false,
        static function () use ($privateRoot, $spaceId, $root, $limit, $recipients, $payload, $publicRoot): bool {
            $path = $root . '/email-usage.json';
            $day = gmdate('Y-m-d');
            $previous = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $used = is_array($previous) && ($previous['day'] ?? null) === $day ? (int) ($previous['recipients'] ?? 0) : 0;
            if ($used + $recipients > $limit) {
                return false;
            }
            // Reserve before transport acceptance. An interrupted ambiguous
            // send consumes allowance but never becomes a usage charge.
            _stattic_runtime_write_json_atomic($path, ['day' => $day, 'recipients' => $used + $recipients]);
            $accepted = _stattic_mail_send($payload, $publicRoot);
            if ($accepted) {
                _stattic_record_retail_usage($privateRoot, $spaceId, 'email_recipients', $recipients);
            } else {
                _stattic_runtime_write_json_atomic($path, ['day' => $day, 'recipients' => $used]);
            }
            return $accepted;
        },
    );
}
