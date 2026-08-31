<?php

namespace Z77\Shared\Mail;

/**
 * {@see MailTransport} implementation that DELIVERS TO DISK — the development
 * box's answer to «no local MTA». Selected via `config/mail.inc.php`
 * `transport = 'file'`.
 *
 * A dev machine has no sendmail, so every mail took the graceful failure path
 * and everything that hangs on a mail — the magic-link login above all — could
 * only be walked through on a real host. That made a deploy the cheapest way
 * to test a login, which is the wrong price for the wrong thing.
 *
 * What lands here is the SAME RFC 5322 blob the SMTP and mail() transports
 * hand to their MTA — not a summary and not a re-rendering. So the file shows
 * the mail as the recipient would receive it: subject line, headers, both
 * bodies. That matters beyond convenience for B8, where the decision belongs
 * in the inbox: the check digits ride in the SUBJECT, and a transport that
 * rendered its own version could not prove they are there.
 *
 * ⚠️ This is a development transport. It never delivers, and it writes plain
 * text — including whatever a mail carries in the clear (a magic-link token
 * IS a credential until it is redeemed). Belongs in a machine-specific
 * `config/mail.inc.php`, never on a server.
 */
final class FileTransport implements MailTransport
{
    public function __construct(private string $directory) {}

    public function send(string $sender, array $recipients, string $data): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Mail outbox directory cannot be created: {$this->directory}");
        }

        // Sortable by name, and the recipient readable without opening it —
        // an outbox is scanned far more often than it is read.
        $name = date('Ymd-His')
            . '-' . substr((string) hrtime(true), -4)
            . '-' . $this->slug($recipients[0] ?? $sender)
            . '.eml';

        if (@file_put_contents($this->directory . '/' . $name, $data) === false) {
            throw new \RuntimeException("Mail outbox is not writable: {$this->directory}");
        }
    }

    /** Address → file-name-safe fragment; the full address stays in the headers. */
    private function slug(string $address): string
    {
        return substr(preg_replace('/[^a-z0-9._-]+/i', '-', $address) ?? 'mail', 0, 40);
    }
}
