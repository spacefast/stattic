<?php

declare(strict_types=1);

namespace TypedPress;

use TypedPress\Jobs\Jobs;

/**
 * `mail.send(message)` from "typedpress": queue one email inside the caller's transaction and hand it to
 * WordPress after commit. A rollback sends nothing; a failed hand-off is retried by the job runner.
 *
 * The message has `from`, `to`, `subject`, `text`/`html`, `cc`, `bcc`,
 * `replyTo`, `headers`. It is normalized and checked here, before queueing, so a bad address fails
 * the request instead of a background attempt.
 *
 * Delivery first offers the message to the optional `tp_mail_transport` filter, so a host can send
 * it itself (Spacefast routes it to the Space's own sender, with its domain check and recipient
 * allowance). A transport answers `true` when it sent the message, and `false` or a throw to refuse
 * it for a retry. When no transport takes it (the filter answers `null`), the message goes through
 * `wp_mail` and whatever mail plugin or host mail service the site runs, managed builds included.
 * Nothing here opens an SMTP connection.
 */
final class Mail
{
    public const JOB = 'typedpress.mail';
    /** The default sender of a local site whose host is no mail domain. `.localhost` never leaves the machine. */
    public const LOCAL_SENDER = 'noreply@typedpress.localhost';
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

    /**
     * @return JsObject `{ id }` of the queued delivery; `''` while a preview seeds, which checks the
     * message and queues nothing ({@see Preview::quiet()})
     */
    public static function send(mixed $message): JsObject
    {
        $payload = self::payload(Seam::toPhp($message));
        if (Preview::quiet()) {
            return new JsObject(['id' => '']);
        }
        $job = Jobs::enqueue(self::JOB, ['message' => $payload]);

        return new JsObject(['id' => $job['id']]);
    }

    /**
     * Validate message fields, recipients, and headers before enqueueing a send.
     *
     * @return array<string, mixed>
     */
    public static function payload(mixed $message): array
    {
        if (!is_array($message)) {
            throw HttpError::invalidInput('A message object is required.', '');
        }
        // `replyTo?: MailAddress` invites `replyTo: settings.replyTo ?? undefined`: a field holding
        // JavaScript's undefined is a field left out, here and inside an address.
        $message = self::withoutUndefined($message);
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
        // A host transport that answers takes the message: true is sent, anything else but null is a
        // refusal the runner retries. null means no transport took it, so WordPress sends it.
        $accepted = apply_filters('tp_mail_transport', null, $payload);
        if ($accepted === true) {
            return;
        }
        if ($accepted !== null) {
            throw new \RuntimeException('The site mail transport did not accept the message');
        }
        $mail = self::wpMailArguments($payload);
        if (!self::sendWordPress($mail['to'], $mail['subject'], $mail['message'], $mail['headers'], $mail['alt'])) {
            throw new \RuntimeException('Mail transport did not accept the message');
        }
    }

    public static function sendWordPress(array|string $to, string $subject, string $body, array $headers, ?string $alt): bool
    {
        if ($alt === null) {
            return (bool) wp_mail($to, $subject, $body, $headers);
        }
        $configure = static function ($mailer) use ($alt): void {
            if (is_object($mailer)) {
                $mailer->AltBody = $alt;
            }
        };
        add_action('phpmailer_init', $configure);
        try {
            return (bool) wp_mail($to, $subject, $body, $headers);
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

    /** A value with every field that holds JavaScript's undefined removed, at any depth. */
    private static function withoutUndefined(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if ($item === JsUndefined::Value) {
                continue;
            }
            $out[$key] = is_array($item) ? self::withoutUndefined($item) : $item;
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    /** `noreply@` the site's own host, the original runtime's default sender. */
    private static function defaultSender(): array
    {
        $configured = Env::get('TP_MAIL_SENDER_DOMAIN');
        $host = function_exists('home_url') ? (string) parse_url(home_url('/'), PHP_URL_HOST) : '';
        $local = function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['local', 'development'], true);

        return self::sender(is_string($configured) ? $configured : '', $host, $local);
    }

    /**
     * The default sender for a site on `$host`: `noreply@` the configured domain, else the host.
     * A host that is no mail domain (`127.0.0.1`, `localhost`) has no sender. On a local or
     * development site the reserved `.localhost` name stands in, so `mail.send` works in
     * `typedpress dev` without configuration; anywhere else the refusal names what to set.
     *
     * @return array{email: string}
     */
    public static function sender(string $configured, string $host, bool $local): array
    {
        $domain = strtolower($configured !== '' ? $configured : $host);
        $email = 'noreply@' . $domain;
        if ($domain !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return ['email' => $email];
        }
        if ($configured === '' && $local) {
            return ['email' => self::LOCAL_SENDER];
        }
        $what = $configured !== '' ? "TP_MAIL_SENDER_DOMAIN ($configured)" : ($host === '' ? 'This site' : "This site's host ($host)");

        throw HttpError::invalidInput($what . ' is not a domain email can be sent from. Set `from` on the message, or set TP_MAIL_SENDER_DOMAIN to the sending domain.', '/from');
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
