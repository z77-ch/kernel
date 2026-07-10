<?php

namespace Z77\Shared\Mail;

/**
 * Sends a ready-built MIME message (DMS Phase 6). The envelope (`sender` + `recipients`)
 * is passed separately from the `data` blob because it drives `MAIL FROM` / `RCPT TO`
 * and may differ from the visible `From:`/`To:` headers (e.g. Bcc recipients are in the
 * envelope but never in the headers). {@see SmtpTransport} is the production implementation.
 */
interface MailTransport
{
    /**
     * @param list<string> $recipients envelope recipients (to + cc + bcc)
     *
     * @throws \RuntimeException on any protocol/connection failure
     */
    public function send(string $sender, array $recipients, string $data): void;
}
