<?php

declare(strict_types=1);

namespace Zero;

use Zero\Jobs\Jobs;

/**
 * `mail.send(message)` from "zero": queue one email inside the caller's transaction and hand it to
 * WordPress after commit, as the original runtime's `ctx.email.send` did through its outbox. A
 * rollback sends nothing; a failed hand-off is retried by the job runner.
 *
 * The message is the original `EmailMessage`: `from`, `to`, `subject`, `text`/`html`, `cc`, `bcc`,
 * `replyTo`, `headers`. It is normalized and checked here, before queueing, so a bad address fails
 * the request instead of a background attempt.
 *
 * Delivery goes through the `zero_mail_transport` filter when a host supplies one (Spacefast routes
 * it to the Space's own sender, with its domain check and recipient allowance), otherwise through
 * `wp_mail` and whatever mail plugin the site runs. Nothing here opens an SMTP connection.
 */
final class Mail
{
    public const JOB = 'zero.mail';
    private const RETRIES = 5;
    private const MAX_BODY = 262144;
    private const MAX_RECIPIENTS = 50;
    private const RESERVED_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'content-type', 'message-id', 'subject', 'mime-version', 'content-transfer-encoding'];

    public static function spec(): array
    {
        return [
            'input' => ['message' => ['type' => 'json', 'notNull' => true]],
            'handler' => self::deliver(...),
            'retries' => self::RETRIES,
            'agents' => false,
        ];
    }

    /** @return JsObject `{ id }` of the queued delivery */
    public static function send(mixed $message): JsObject
    {
        $payload = self::payload(Seam::toPhp($message));
        $job = Jobs::enqueue(self::JOB, ['message' => $payload]);

        return new JsObject(['id' => $job['id']]);
    }

    /**
     * The original runtime's `emailPayload`: the same required fields, address coercion and header
     * rules, so a message that sent from a 0.8 handler sends from here.
     *
     * @return array<string, mixed>
     */
    public static function payload(mixed $message): array
    {
        if (!is_array($message)) {
            throw HttpError::invalidInput('A message object is required.', '');
        }
        foreach (array_keys($message) as $key) {
            if (!in_array($key, ['from', 'to', 'subject', 'text', 'html', 'cc', 'bcc', 'replyTo', 'headers'], true)) {
                throw HttpError::invalidInput("Unknown message field $key.", '/' . $key);
            }
        }
        $subject = $message['subject'] ?? null;
        if (!is_string($subject) || $subject === '' || preg_match('/[\r\n\0]/', $subject) === 1) {
            throw HttpError::invalidInput('Message subject is required and must be one line.', '/subject');
        }
        $text = is_string($message['text'] ?? null) && $message['text'] !== '' ? $message['text'] : null;
        $html = is_string($message['html'] ?? null) && $message['html'] !== '' ? $message['html'] : null;
        if ($text === null && $html === null) {
            throw HttpError::invalidInput('Message must carry text, html, or both.', '/text');
        }
        if (strlen((string) $text) + strlen((string) $html) > self::MAX_BODY) {
            throw HttpError::invalidInput('Message body is too large.', '/text');
        }
        $to = self::addresses($message['to'] ?? null, '/to', false);
        $from = $message['from'] ?? null;
        $payload = [
            'from' => $from === null ? self::defaultSender() : self::address($from, '/from'),
            'to' => $to,
            'subject' => $subject,
        ];
        if ($text !== null) {
            $payload['text'] = $text;
        }
        if ($html !== null) {
            $payload['html'] = $html;
        }
        foreach (['cc', 'bcc'] as $field) {
            if (($message[$field] ?? null) !== null) {
                $payload[$field] = self::addresses($message[$field], '/' . $field, true);
            }
        }
        if (($message['replyTo'] ?? null) !== null) {
            $payload['replyTo'] = self::address($message['replyTo'], '/replyTo');
        }
        $count = count($payload['to']) + count($payload['cc'] ?? []) + count($payload['bcc'] ?? []);
        if ($count > self::MAX_RECIPIENTS) {
            throw HttpError::invalidInput('A message may have at most ' . self::MAX_RECIPIENTS . ' recipients.', '/to');
        }
        if (($message['headers'] ?? null) !== null) {
            if (!is_array($message['headers'])) {
                throw HttpError::invalidInput('Message headers must be an object.', '/headers');
            }
            $headers = [];
            foreach ($message['headers'] as $name => $value) {
                $name = (string) $name;
                if (!is_string($value) || preg_match('/[\r\n\0]/', $value) === 1) {
                    throw HttpError::invalidInput("Header $name must be a single-line string.", '/headers/' . $name);
                }
                if (preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) !== 1 || in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                    throw HttpError::invalidInput("Header $name is not a header a message may set.", '/headers/' . $name);
                }
                $headers[$name] = $value;
            }
            $payload['headers'] = $headers;
        }

        return $payload;
    }

    /** The job handler: one hand-off; a refusal throws so the runner backs off and retries. */
    public static function deliver(array $input, array $ctx): void
    {
        // json() job input arrives with JavaScript object semantics; delivery reads plain arrays.
        $payload = json_decode((string) Js::jsonStringify($input['message'] ?? null), true);
        if (!is_array($payload) || !isset($payload['to'], $payload['subject'], $payload['from'])) {
            throw new \RuntimeException('Queued message is missing');
        }
        // A managed host owns sending: a Space's mail must leave through its own sender, domain check
        // and allowance, never a second path. Without the host's transport a managed app refuses.
        if (function_exists('has_filter') && has_filter('zero_mail_transport')) {
            $accepted = apply_filters('zero_mail_transport', null, $payload);
            if ($accepted !== true) {
                throw new \RuntimeException('The site mail transport did not accept the message');
            }
            return;
        }
        if (Wp\Runtime::managed()) {
            throw new \RuntimeException('This host has not connected its mail transport (zero_mail_transport)');
        }
        $mail = self::wpMailArguments($payload);
        $alt = $mail['alt'];
        $configure = static function ($mailer) use ($alt): void {
            if ($alt !== null && is_object($mailer)) {
                $mailer->AltBody = $alt;
            }
        };
        add_action('phpmailer_init', $configure);
        try {
            if (!wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers'])) {
                throw new \RuntimeException('Mail transport did not accept the message');
            }
        } finally {
            remove_action('phpmailer_init', $configure);
        }
    }

    /**
     * `wp_mail` arguments for a normalized payload, with names RFC 2047-encoded so WordPress's comma
     * splitting can never turn a display name into another recipient.
     *
     * @return array{to: list<string>, subject: string, message: string, headers: list<string>, alt: ?string}
     */
    public static function wpMailArguments(array $payload): array
    {
        $format = static fn (array $a): string => isset($a['name']) ? self::encodedName($a['name']) . ' <' . $a['email'] . '>' : $a['email'];
        $headers = ['From: ' . $format($payload['from']), 'Reply-To: ' . $format($payload['replyTo'] ?? $payload['from'])];
        foreach (['cc' => 'Cc', 'bcc' => 'Bcc'] as $field => $name) {
            if (($payload[$field] ?? []) !== []) {
                $headers[] = $name . ': ' . implode(', ', array_map($format, $payload[$field]));
            }
        }
        $html = $payload['html'] ?? null;
        if ($html !== null) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        foreach ($payload['headers'] ?? [] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return [
            'to' => array_map($format, $payload['to']),
            'subject' => $payload['subject'],
            'message' => $html ?? $payload['text'],
            'headers' => $headers,
            'alt' => $html !== null ? ($payload['text'] ?? null) : null,
        ];
    }

    /** @return array{email: string, name?: string} */
    private static function address(mixed $value, string $pointer): array
    {
        if (is_string($value)) {
            $value = ['email' => $value];
        }
        if (!is_array($value) || !is_string($value['email'] ?? null) || array_diff(array_keys($value), ['email', 'name']) !== []) {
            throw HttpError::invalidInput('An address is a string or { email, name? }.', $pointer);
        }
        $email = trim($value['email']);
        // WordPress splits address headers on commas, so a comma can never sit inside one address.
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || str_contains($email, ',')) {
            throw HttpError::invalidInput('Not a valid email address.', $pointer);
        }
        $out = ['email' => $email];
        if (array_key_exists('name', $value) && $value['name'] !== null && $value['name'] !== '') {
            if (!is_string($value['name']) || preg_match('/[\r\n\0]/', $value['name']) === 1 || preg_match('//u', $value['name']) !== 1) {
                throw HttpError::invalidInput('A display name is one line of UTF-8.', $pointer . '/name');
            }
            $out['name'] = $value['name'];
        }

        return $out;
    }

    /** @return list<array{email: string, name?: string}> */
    private static function addresses(mixed $value, string $pointer, bool $allowEmpty): array
    {
        $list = is_array($value) && array_is_list($value) ? $value : ($value === null ? null : [$value]);
        if ($list === null || (!$allowEmpty && $list === [])) {
            throw HttpError::invalidInput('At least one address is required.', $pointer);
        }

        return array_map(fn (mixed $a, int $i): array => self::address($a, $pointer . '/' . $i), $list, array_keys($list));
    }

    /** `noreply@` the site's own host, the original runtime's default sender. */
    private static function defaultSender(): array
    {
        $domain = Env::get('ZERO_MAIL_SENDER_DOMAIN');
        if (($domain === null || $domain === '') && function_exists('home_url')) {
            $domain = (string) parse_url(home_url('/'), PHP_URL_HOST);
        }
        if ($domain === null || $domain === '') {
            throw HttpError::invalidInput('Message from is required.', '/from');
        }

        return self::address('noreply@' . strtolower($domain), '/from');
    }

    /** RFC 2047 encoded-words below 75 characters each, never splitting a UTF-8 sequence. */
    private static function encodedName(string $name): string
    {
        $words = [];
        $length = strlen($name);
        for ($offset = 0; $offset < $length; $offset = $end) {
            $end = min($offset + 45, $length);
            while ($end < $length && (ord($name[$end]) & 0xC0) === 0x80) {
                $end--;
            }
            $words[] = '=?UTF-8?B?' . base64_encode(substr($name, $offset, $end - $offset)) . '?=';
        }

        return implode(' ', $words);
    }
}
