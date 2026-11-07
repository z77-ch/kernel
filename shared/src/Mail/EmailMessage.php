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
    private string $replyToName = '';
    private string $subject = '';
    private string $text = '';
    private string $template = '';
    private string $templateNameSpace = '';
    /** @var array<string, mixed> */
    private array $context = [];
    /** @var list<array{path: string, name: string}> */
    private array $attachments = [];

    /** The recipient's language; null = the current one (the request's). */
    private ?string $language = null;

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

    /** The From DISPLAY NAME only: the address stays the installation's ({@see Message::fromName()}). */
    public function fromName(string $fromName): self
    {
        $this->fromName = $fromName;
        return $this;
    }

    /** The person who triggers this mail signs it ({@see SenderIdentity}: display name + Reply-To). */
    public function signedBy(SenderIdentity $sender): self
    {
        return $this->fromName($sender->name)->replyTo($sender->email, $sender->name);
    }

    public function cc(?string $cc): self
    {
        $this->cc = str_replace(';', ',', (string) $cc);
        return $this;
    }

    public function replyTo(?string $replyTo, string $name = ''): self
    {
        $this->replyTo     = (string) $replyTo;
        $this->replyToName = $name;
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

    /**
     * The language the BODY renders in — the recipient's, which need not be the request's (a
     * member's mail sent from the backend, a job, an API call). {@see EmailService} renders
     * template and layout inside `Translator::inLanguage()`, so every `t()` in them answers in
     * it without passing it along. The SUBJECT is a string the caller builds: translate it
     * with the same language, `t($key, $params, $language)`. Null = the current language.
     */
    public function language(?string $language): self
    {
        $this->language = $language !== null && trim($language) !== '' ? trim($language) : null;
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
    public function getReplyToName(): string { return $this->replyToName; }
    public function getSubject(): string { return $this->subject; }
    public function getText(): string { return $this->text; }
    public function getTemplate(): string { return $this->template; }
    public function getTemplateNameSpace(): string { return $this->templateNameSpace; }
    /** @return array<string, mixed> */
    public function getContext(): array { return $this->context; }
    /** @return list<array{path: string, name: string}> */
    public function getAttachments(): array { return $this->attachments; }
    public function getLanguage(): ?string { return $this->language; }
}
