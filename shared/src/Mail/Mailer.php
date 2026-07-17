<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;

/**
 * The mail façade (DMS Phase 6, ADR-016 / OPEN-5). Reads `config/mail.inc.php`, builds the
 * configured transport (`transport`: 'smtp' → {@see SmtpTransport}, 'mail' →
 * {@see PhpMailTransport}), holds the installation's default sender, and sends a
 * {@see Message} (filling in the default From when the message has none). When mail is not
 * configured (file absent or `enabled = false`) the mailer is in an unconfigured state and
 * {@see send()} throws a clear error rather than failing deep in the transport layer.
 *
 * Not a DI singleton (placement decision B, like `DocumentService`): built on demand via
 * {@see create()}; the explicit constructor takes a transport for isolated testing.
 */
final class Mailer
{
    public function __construct(
        private ?MailTransport $transport,
        private string $fromAddress = '',
        private string $fromName = '',
    ) {}

    public static function create(): self
    {
        $cfg = DI::getConfigManager()->getBaseConfig('config/mail', throwError: false);

        if (!(bool) $cfg->get('enabled', false)) {
            return new self(null);
        }

        // 'mail' = PHP mail() via local MTA (shared hosting, no credentials);
        // 'smtp' (default) = the existing socket transport.
        $transport = (string) $cfg->get('transport', 'smtp') === 'mail'
            ? new PhpMailTransport()
            : new SmtpTransport(
                host:       (string) $cfg->get('host', ''),
                port:       (int)    $cfg->get('port', 587),
                encryption: (string) $cfg->get('encryption', 'tls'),
                username:   (string) $cfg->get('username', ''),
                password:   (string) $cfg->get('password', ''),
                timeout:    (int)    $cfg->get('timeout', 15),
                heloHost:   (string) $cfg->get('heloHost', 'localhost'),
            );

        return new self(
            $transport,
            (string) $cfg->get('fromAddress', ''),
            (string) $cfg->get('fromName', ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->transport !== null;
    }

    /**
     * @throws \RuntimeException when mail is not configured, the message is invalid, or
     *                           the transport fails
     */
    public function send(Message $message): void
    {
        if ($this->transport === null) {
            throw new \RuntimeException('Mail is not configured (config/mail.inc.php missing or enabled = false).');
        }

        if ($message->getFromAddress() === '' && $this->fromAddress !== '') {
            $message->from($this->fromAddress, $this->fromName);
        }

        $built = MimeMessage::build($message);
        $this->transport->send($built['sender'], $built['recipients'], $built['data']);
    }
}
