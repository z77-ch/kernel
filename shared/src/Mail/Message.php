<?php

namespace Z77\Shared\Mail;

/**
 * An e-mail to be sent (DMS Phase 6, ADR-016 / OPEN-5 — own build, no dependency). A
 * fluent value object: recipients, subject, a plain-text body (and an optional HTML
 * alternative), plus {@see Attachment}s. {@see MimeMessage} turns it into the RFC 5322
 * wire format; {@see MailTransport} sends it.
 *
 * Every address and the subject pass through {@see assertHeaderSafe()} / address
 * validation on the way in: a CR/LF in a header field is the classic mail-injection
 * vector (smuggling extra recipients/headers), so an invalid value throws immediately
 * rather than being silently stripped at build time.
 */
final class Message
{
    private string $fromAddress = '';
    private string $fromName = '';
    /** @var list<string> */
    private array $to = [];
    /** @var list<string> */
    private array $cc = [];
    /** @var list<string> */
    private array $bcc = [];
    private string $replyTo = '';
    private string $subject = '';
    private string $textBody = '';
    private ?string $htmlBody = null;
    /** @var list<Attachment> */
    private array $attachments = [];

    public function from(string $address, string $name = ''): self
    {
        $this->fromAddress = $this->assertEmail($address);
        $this->fromName    = $this->assertHeaderSafe($name);
        return $this;
    }

    public function to(string ...$addresses): self    { return $this->add('to', $addresses); }
    public function cc(string ...$addresses): self    { return $this->add('cc', $addresses); }
    public function bcc(string ...$addresses): self   { return $this->add('bcc', $addresses); }

    public function replyTo(string $address): self
    {
        $this->replyTo = $this->assertEmail($address);
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $this->assertHeaderSafe($subject);
        return $this;
    }

    public function text(string $body): self
    {
        $this->textBody = $body;
        return $this;
    }

    public function html(string $body): self
    {
        $this->htmlBody = $body;
        return $this;
    }

    public function attach(Attachment $attachment): self
    {
        $this->attachments[] = $attachment;
        return $this;
    }

    // ── getters (used by MimeMessage) ────────────────────────────────────────────
    public function getFromAddress(): string { return $this->fromAddress; }
    public function getFromName(): string { return $this->fromName; }
    /** @return list<string> */
    public function getTo(): array { return $this->to; }
    /** @return list<string> */
    public function getCc(): array { return $this->cc; }
    /** @return list<string> */
    public function getBcc(): array { return $this->bcc; }
    public function getReplyTo(): string { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getTextBody(): string { return $this->textBody; }
    public function getHtmlBody(): ?string { return $this->htmlBody; }
    /** @return list<Attachment> */
    public function getAttachments(): array { return $this->attachments; }

    /** All envelope recipients (to + cc + bcc), de-duplicated. @return list<string> */
    public function getRecipients(): array
    {
        return array_values(array_unique([...$this->to, ...$this->cc, ...$this->bcc]));
    }

    /**
     * @param list<string> $addresses
     */
    private function add(string $field, array $addresses): self
    {
        foreach ($addresses as $address) {
            $this->{$field}[] = $this->assertEmail($address);
        }
        return $this;
    }

    private function assertEmail(string $address): string
    {
        $address = trim($address);
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException("Invalid e-mail address: '{$address}'.");
        }
        return $address;
    }

    private function assertHeaderSafe(string $value): string
    {
        if (preg_match('/[\r\n]/', $value)) {
            throw new \InvalidArgumentException('Header value must not contain CR or LF (mail injection).');
        }
        return trim($value);
    }
}
