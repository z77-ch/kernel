<?php

namespace Z77\Shared\Mail;

use Z77\Persistence\Cleaning\Filters\EmailFilter;
use Z77\Persistence\Cleaning\Filters\TextFilter;

/**
 * One way a staff member signs a mail he triggers (owner decision 2026-10-23): a DISPLAY NAME
 * and an E-MAIL. A backend user keeps 0..n of them (`BackendUser::$senderIdentities`, the first
 * is his default — {@see SenderIdentities}).
 *
 * What an identity does to a mail — and what it does NOT:
 *
 *   - the header From's DISPLAY NAME becomes `name`; the From ADDRESS and the envelope sender
 *     stay the installation's account (`config/client/mail.inc.php` `fromAddress`) — the
 *     address SPF / DKIM / DMARC are aligned to (mail.md);
 *   - Reply-To becomes «name <email>»: the customer's answer reaches the person, not the
 *     no-reply account.
 *
 * A value object without checks of its own — the constructor never throws, so a stored list
 * with a bad row still loads; {@see problems()} is the one rule the user form
 * (`BackendUserValidator`) and the outbox (`OutboxService::problems()`) both ask.
 */
final class SenderIdentity
{
    /** The display name's length — the full-name length of `BackendUser::NAME_LENGTH`. */
    public const NAME_LENGTH = 80;

    /** RFC 5321: a forward path holds at most 254 octets of address. */
    public const EMAIL_LENGTH = 254;

    /**
     * Characters a display name must not carry: CR / LF (header injection), the quote and the
     * backslash (they end or escape the quoted header word), `<` `>` (they open an address) and
     * `@` — a display name that reads like an address is the classic look-alike sender.
     */
    private const NAME_FORBIDDEN = '/[\r\n"\\\\<>@]/';

    public function __construct(
        public readonly string $name,
        public readonly string $email,
    ) {
    }

    /** From a stored row `{name, email}`; anything that is not a string reads ''. */
    public static function fromArray(array $row): self
    {
        $name  = $row['name'] ?? '';
        $email = $row['email'] ?? '';

        return new self(is_string($name) ? trim($name) : '', is_string($email) ? trim($email) : '');
    }

    /**
     * The rows of the user form: two parallel maps keyed alike (`identity_name[k]`,
     * `identity_email[k]` — core.js nests one bracket level in a fetch form), in posted order.
     * A row with both fields empty is no row; the values are cleaned as the entity's `text` /
     * `email` fields are (control characters out, white space collapsed, the address lower-case).
     *
     * @return list<self>
     */
    public static function listFromForm(mixed $names, mixed $emails): array
    {
        $names  = is_array($names) ? $names : [];
        $emails = is_array($emails) ? $emails : [];
        $text   = new TextFilter();
        $mail   = new EmailFilter();
        $list   = [];
        foreach (array_unique([...array_keys($names), ...array_keys($emails)]) as $key) {
            $name  = (string) $text->apply($names[$key] ?? '');
            $email = (string) $mail->apply($emails[$key] ?? '');
            if ($name !== '' || $email !== '') {
                $list[] = new self($name, $email);
            }
        }

        return $list;
    }

    /** @return array{name: string, email: string} the stored row */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    /** «Peter Ruepp <peter.ruepp@z77.ch>» — the choice on a send page, the outbox window. */
    public function label(): string
    {
        return $this->name === '' ? $this->email : $this->name . ' <' . $this->email . '>';
    }

    /**
     * Every reason this identity cannot sign a mail, German, empty when it can.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        if ($this->name === '') {
            $problems[] = 'Anzeigename fehlt.';
        } elseif (mb_strlen($this->name) > self::NAME_LENGTH) {
            $problems[] = 'Der Anzeigename hat höchstens ' . self::NAME_LENGTH . ' Zeichen.';
        } elseif (preg_match(self::NAME_FORBIDDEN, $this->name) === 1) {
            $problems[] = 'Der Anzeigename darf keine Zeichen wie " \\ < > @ enthalten.';
        }
        if ($this->email === '') {
            $problems[] = 'E-Mail-Adresse fehlt.';
        } elseif (strlen($this->email) > self::EMAIL_LENGTH || !MailAddress::isDeliverable($this->email)) {
            $problems[] = 'E-Mail-Adresse «' . $this->email . '» ist ungültig.';
        }

        return $problems;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && strcasecmp($this->email, $other->email) === 0;
    }
}
