<?php

declare(strict_types=1);

namespace Zero\Services;

/** WordPress owns MIME, PHPMailer and transport integrations; the existing job owns retries. */
final class Mail
{
    public static function deliver(array $input, array $ctx): void
    {
        $row = Storage::run('SELECT * FROM {submissions} WHERE `id` = ?', [$input['submissionId']])[0]
            ?? throw new \RuntimeException('Submission no longer exists');
        $form = Module::config()['forms'][$row['form']] ?? throw new \RuntimeException('Form no longer configured');
        $to = Module::secret($form['recipientEnv']);
        $from = Module::secret($form['senderEnv']);
        if (!is_email($to) || !is_email($from) || strpbrk($to . $from, "\r\n") !== false) {
            throw new \RuntimeException('Mail addresses must be individual valid email addresses');
        }
        $values = json_decode($row['body'], true, 32, JSON_THROW_ON_ERROR);
        $lines = ['Form: ' . $row['form'], 'Submission: ' . $row['id'], ''];
        foreach ($values as $key => $value) {
            $lines[] = $key . ': ' . (is_scalar($value) ? (string) $value : '');
        }
        $messageId = '<zero-' . $row['id'] . '@' . substr(strrchr($from, '@'), 1) . '>';
        $restore = null;
        $configure = static function ($mailer) use ($messageId, &$restore): void {
            $properties = ['MessageID'];
            $host = \Zero\Env::get('ZERO_SERVICES_SMTP_HOST');
            if ($host !== null && $host !== '') {
                $properties = [...$properties, 'Mailer', 'Host', 'Port', 'SMTPSecure', 'SMTPAutoTLS', 'Username', 'Password', 'SMTPAuth', 'Timeout'];
            }
            $previous = [];
            foreach ($properties as $property) {
                $previous[$property] = $mailer->$property;
            }
            $previousSmtp = null;
            if ($host !== null && $host !== '') {
                // A plugin may keep a connection open. Give this override its own connection so
                // PHPMailer cannot reuse that plugin's socket for a different configured host.
                $previousSmtp = $mailer->getSMTPInstance();
                $mailer->setSMTPInstance(new \PHPMailer\PHPMailer\SMTP());
            }
            $restore = static function () use ($mailer, $previous, $previousSmtp): void {
                if ($previousSmtp !== null) {
                    $mailer->smtpClose();
                    $mailer->setSMTPInstance($previousSmtp);
                }
                foreach ($previous as $property => $value) {
                    $mailer->$property = $value;
                }
            };
            $mailer->MessageID = $messageId;
            // Optional native SMTP. With no host, existing WordPress mail plugins remain authoritative.
            if ($host === null || $host === '') {
                return;
            }
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = (int) (\Zero\Env::get('ZERO_SERVICES_SMTP_PORT') ?? '587');
            $mailer->SMTPSecure = \Zero\Env::get('ZERO_SERVICES_SMTP_ENCRYPTION') ?? 'tls';
            $mailer->SMTPAutoTLS = $mailer->SMTPSecure !== '';
            $mailer->Username = \Zero\Env::get('ZERO_SERVICES_SMTP_USER') ?? '';
            $mailer->Password = \Zero\Env::get('ZERO_SERVICES_SMTP_PASSWORD') ?? '';
            $mailer->SMTPAuth = $mailer->Username !== '';
            $mailer->Timeout = 15;
        };
        add_action('phpmailer_init', $configure, 100);
        try {
            if (!wp_mail($to, 'New ' . $row['form'] . ' submission', implode("\n", $lines), ['From: ' . $from, 'Content-Type: text/plain; charset=UTF-8'])) {
                throw new \RuntimeException('Mail transport did not accept the message');
            }
        } finally {
            remove_action('phpmailer_init', $configure, 100);
            $restore?->__invoke();
        }
    }
}
