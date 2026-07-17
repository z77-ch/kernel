<?php

namespace Z77\Shared\Mail;

/**
 * {@see MailTransport} implementation over PHP's `mail()` — for shared hosting
 * (cyon) where a local sendmail is provided and no SMTP credentials exist.
 * Selected via `config/mail.inc.php` `transport = 'mail'`.
 *
 * `mail()` insists on receiving the To and Subject separately (it writes those
 * headers itself), so the ready-built RFC 5322 blob from {@see MimeMessage} is
 * split here: To/Subject are extracted, every other header travels via
 * additional_headers. Envelope recipients that appear in no visible header
 * (Bcc) are appended as a `Bcc:` header — sendmail (`-t`) and PHP's Windows
 * SMTP mailer both deliver and strip it. The envelope sender is forced with
 * `-f` so bounces/SPF align with the From address (the address is validated
 * by {@see Message} before it ever gets here; PHP shell-escapes the
 * parameter internally).
 */
final class PhpMailTransport implements MailTransport
{
    public function send(string $sender, array $recipients, string $data): void
    {
        $args = $this->buildMailArgs($sender, $recipients, $data);

        $ok = mail(
            $args['to'],
            $args['subject'],
            $args['body'],
            $args['headers'],
            '-f' . $sender
        );

        if (!$ok) {
            throw new \RuntimeException(
                'PHP mail() refused the message — no/misconfigured local MTA '
                . '(sendmail_path or Windows SMTP ini settings).'
            );
        }
    }

    /**
     * Splits the RFC 5322 blob into the pieces mail() wants.
     *
     * @param list<string> $recipients envelope recipients (to + cc + bcc)
     *
     * @return array{to: string, subject: string, headers: string, body: string}
     */
    private function buildMailArgs(string $sender, array $recipients, string $data): array
    {
        $headerEnd = strpos($data, "\r\n\r\n");
        if ($headerEnd === false) {
            throw new \RuntimeException('Malformed MIME message: missing header/body separator.');
        }

        $headerBlock = substr($data, 0, $headerEnd);
        $body        = substr($data, $headerEnd + 4);

        $to      = '';
        $subject = '';
        $rest    = [];
        $visible = [];

        foreach (explode("\r\n", $headerBlock) as $line) {
            if (stripos($line, 'To: ') === 0) {
                $to        = substr($line, 4);
                $visible[] = $to;
            } elseif (stripos($line, 'Subject: ') === 0) {
                $subject = substr($line, 9);
            } else {
                if (stripos($line, 'Cc: ') === 0) {
                    $visible[] = substr($line, 4);
                }
                $rest[] = $line;
            }
        }

        if (trim($to) === '') {
            throw new \RuntimeException('PhpMailTransport requires a To recipient.');
        }

        // Envelope recipients missing from To/Cc are Bcc — hand them to the MTA
        // as a Bcc header (delivered and stripped by sendmail / the win32 mailer).
        $visibleAddresses = array_map('trim', explode(',', implode(',', $visible)));
        $bcc = array_values(array_diff($recipients, $visibleAddresses));
        if ($bcc !== []) {
            $rest[] = 'Bcc: ' . implode(', ', $bcc);
        }

        return [
            'to'      => $to,
            'subject' => $subject,
            'headers' => implode("\r\n", $rest),
            'body'    => $body,
        ];
    }
}
