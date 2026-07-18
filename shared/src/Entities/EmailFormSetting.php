<?php
namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * Backend-editable mail settings for ONE form key (EmailService v2 — see
 * docs/03-development/email-settings-v2-bauplan.md and docs/topics/mail.md).
 *
 * A record OVERRIDES the emailConfig `forms[form_key]` entry completely for
 * to/cc/subject/routes; the body template (and the form key's existence)
 * always stays in config — templates are developer artifacts. No record →
 * config values apply (config = seed/fallback, navigation.default.json
 * pattern).
 *
 * `routes` maps a server-defined route key (chosen by a validated form option,
 * NEVER free user input) to a recipient override:
 *   routes = { "verwaltung": { "to": ["a@x.ch", "b@x.ch"], "subject": "…" } }
 * `subject` per route is optional (absent → the record's default subject).
 *
 * Recipient entries are literal addresses in v2. The reserved `ref:{source}:{id}`
 * format (e.g. `ref:customer:1042`) is the v3 Kundenstamm seam — the validator
 * rejects it on save until the resolver exists.
 *
 * Not marked invalidatesCache — mail settings never render into cached pages.
 */
#[Entity('file', 'framework/mail/emailFormSettings.json')]
class EmailFormSetting
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    #[Clean('text')]
    private string $formKey = '';

    /**
     * Whether this override is in effect. false → the record is kept but dormant
     * and resolution falls back to the emailConfig seed (lets the operator
     * temporarily disable an override, e.g. during a form change, without losing
     * the entered recipients). Server-controlled — toggled from the list, never
     * mapped from the edit form. New overrides default to active.
     */
    private bool $active = true;

    /** @var list<string> recipient addresses (server-validated, never from free input) */
    private array $to = [];

    /** @var list<string> */
    private array $cc = [];

    #[Clean('text')]
    private string $subject = '';

    /** @var array<string, array{to: list<string>, subject?: string}> */
    private array $routes = [];

    public function getId(): ?int        { return $this->id; }
    public function getFormKey(): string { return $this->formKey; }
    public function isActive(): bool     { return $this->active; }
    /** @return list<string> */
    public function getTo(): array       { return $this->to; }
    /** @return list<string> */
    public function getCc(): array       { return $this->cc; }
    public function getSubject(): string { return $this->subject; }
    /** @return array<string, array{to: list<string>, subject?: string}> */
    public function getRoutes(): array   { return $this->routes; }

    public function setFormKey(string $formKey): void { $this->formKey = $formKey; }
    public function setActive(bool $active): void     { $this->active = $active; }
    /** @param list<string> $to */
    public function setTo(array $to): void            { $this->to = array_values($to); }
    /** @param list<string> $cc */
    public function setCc(array $cc): void            { $this->cc = array_values($cc); }
    public function setSubject(string $subject): void { $this->subject = $subject; }
    /** @param array<string, array{to: list<string>, subject?: string}> $routes */
    public function setRoutes(array $routes): void    { $this->routes = $routes; }
}
