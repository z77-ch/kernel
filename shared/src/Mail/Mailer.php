<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;

/**
 * The mail façade (DMS Phase 6, ADR-016 / OPEN-5). Reads `config/client/mail.inc.php`, builds the
 * configured transport (`transport`: 'smtp' → {@see SmtpTransport}, 'mail' →
 * {@see PhpMailTransport}, 'file' → {@see FileTransport}, development only),
 * holds the installation's default sender, and sends a
 * {@see Message} (filling in the default From when the message has none, and the named inline
 * images of its HTML — {@see InlineImages}). When mail is not
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
        private ?InlineImages $inlineImages = null,
    ) {}

    public static function create(): self
    {
        $cfg = DI::getConfigManager()->getBaseConfig('config/mail', throwError: false);

        if (!(bool) $cfg->get('enabled', false)) {
            return new self(null);
        }

        // 'mail' = PHP mail() via local MTA (shared hosting, no credentials);
        // 'file' = delivery to disk, for a development box without an MTA;
        // 'smtp' (default) = the existing socket transport.
        $transport = match ((string) $cfg->get('transport', 'smtp')) {
            'mail'  => new PhpMailTransport(),
            'file'  => new FileTransport(
                (string) $cfg->get('outbox', ABS_BASE_PATH . '/data/framework/mail/outbox')
            ),
            default => new SmtpTransport(
                host:       (string) $cfg->get('host', ''),
                port:       (int)    $cfg->get('port', 587),
                encryption: (string) $cfg->get('encryption', 'tls'),
                username:   (string) $cfg->get('username', ''),
                password:   (string) $cfg->get('password', ''),
                timeout:    (int)    $cfg->get('timeout', 15),
                heloHost:   (string) $cfg->get('heloHost', 'localhost'),
            ),
        };

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
            throw new \RuntimeException('Mail is not configured (config/client/mail.inc.php missing or enabled = false).');
        }

        // The ADDRESS is always the account's when the message has none; a display name the
        // message carries (a sender identity, `Message::fromName()`) wins over the config's.
        if ($message->getFromAddress() === '' && $this->fromAddress !== '') {
            $message->from($this->fromAddress, $message->getFromName() !== '' ? $message->getFromName() : $this->fromName);
        }

        // Inline images (the e-mail logo, 2026-10-23) are resolved HERE, at send time — the one
        // place the outbox and EmailService::send() both pass, so a logo changed after a mail
        // was queued is the one that goes out. A body without a slot never loads the registry.
        if (InlineImages::hasSlots($message->getHtmlBody())) {
            ($this->inlineImages ?? InlineImages::fromModules(DI::getModuleManager()))->embedInto($message);
        }

        $built = MimeMessage::build($message);
        $this->transport->send($built['sender'], $built['recipients'], $built['data']);
    }
}
