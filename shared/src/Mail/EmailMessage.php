<?php

namespace Z77\Shared\Mail;

/**
 * Fluent builder for a template-based e-mail, consumed by {@see EmailService}.
 * Holds only intent — nothing is rendered, validated, or sent here. The service
 * renders the template (override-first via FileFinder) into the shared layout,
 * derives the plain-text part ({@see HtmlToText}), and maps everything onto the
 * hardened {@see Message} VO, which is where address/header validation happens.
 *
 * A mail is either template-based (`template()` → HTML + derived text) or a
 * simple text mail (`text()` only). Multi-recipient strings may use `;` or `,`
 * as separator (Outlook copy-paste) — normalised to `,` on the way in.
 */
final class EmailMessage
{
    private string $to = '';
    private string $from = '';
    private string $fromName = '';
    private string $cc = '';
    private string $replyTo = '';
    private string $subject = '';
    private string $text = '';
    private string $template = '';
    private string $templateNameSpace = '';
    /** @var array<string, mixed> */
    private array $context = [];
    /** @var list<array{path: string, name: string}> */
    private array $attachments = [];

    public function to(string $to): self
    {
        $this->to = str_replace(';', ',', $to);
        return $this;
    }

    public function from(string $from, string $fromName = ''): self
    {
        $this->from     = $from;
        $this->fromName = $fromName;
        return $this;
    }

    public function cc(?string $cc): self
    {
        $this->cc = str_replace(';', ',', (string) $cc);
        return $this;
    }

    public function replyTo(?string $replyTo): self
    {
        $this->replyTo = (string) $replyTo;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /** Plain-text body for simple mails without a template (single text/plain part). */
    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    /**
     * HTML body template, resolved override-first via FileFinder for the given
     * namespace. `$context` is extracted into the template scope (`e()` applies —
     * values are user input).
     *
     * @param array<string, mixed> $context
     */
    public function template(string $template, string $nameSpace, array $context = []): self
    {
        $this->template          = $template;
        $this->templateNameSpace = $nameSpace;
        $this->context           = $context;
        return $this;
    }

    public function attach(string $absPath, string $fileName): self
    {
        $this->attachments[] = ['path' => $absPath, 'name' => $fileName];
        return $this;
    }

    // ── getters (used by EmailService) ───────────────────────────────────────────
    public function getTo(): string { return $this->to; }
    public function getFrom(): string { return $this->from; }
    public function getFromName(): string { return $this->fromName; }
    public function getCc(): string { return $this->cc; }
    public function getReplyTo(): string { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getText(): string { return $this->text; }
    public function getTemplate(): string { return $this->template; }
    public function getTemplateNameSpace(): string { return $this->templateNameSpace; }
    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context; }
    /** @return list<array{path: string, name: string}> */
    public function getAttachments(): array { return $this->attachments; }
}
