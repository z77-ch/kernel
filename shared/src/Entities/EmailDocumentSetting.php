<?php
namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * The operator's subject and text for ONE document mail kind («Beleg-Mails»,
 * Service › E-Mail — step 3 of the mail plan, owner 2026-10-22). The sibling
 * of {@see EmailFormSetting} on the same screen and in the same directory: a
 * record OVERRIDES the module's declaration
 * ({@see \Z77\Shared\Mail\DocumentMailKinds}) completely while it is active;
 * «Zurücksetzen» deletes it and the declaration applies again; switched off it
 * is kept but dormant.
 *
 *   subjects = { "de": "Rechnung {nummer} — {absender}", "en": "Invoice {nummer} — {absender}" }
 *   bodies   = { "de": "Guten Tag\n\n…", "en": "…" }
 *
 * A language left empty falls back to German (`de`, required by the
 * validator). The texts are plain text with `{placeholder}` markers — the
 * template escapes them; nothing here is markup.
 *
 * Not marked invalidatesCache — mail settings never render into cached pages.
 */
#[Entity('file', 'framework/mail/emailDocumentSettings.json')]
class EmailDocumentSetting
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
    private string $kind = '';

    /** Whether this override is in effect; false → the module's declaration applies. Toggled from the list only. */
    private bool $active = true;

    /** @var array<string, string> language => subject */
    private array $subjects = [];

    /** @var array<string, string> language => text */
    private array $bodies = [];

    public function getId(): ?int    { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function isActive(): bool  { return $this->active; }
    /** @return array<string, string> */
    public function getSubjects(): array { return $this->subjects; }
    /** @return array<string, string> */
    public function getBodies(): array   { return $this->bodies; }

    public function setKind(string $kind): void    { $this->kind = $kind; }
    public function setActive(bool $active): void  { $this->active = $active; }
    /** @param array<string, string> $subjects */
    public function setSubjects(array $subjects): void { $this->subjects = $subjects; }
    /** @param array<string, string> $bodies */
    public function setBodies(array $bodies): void     { $this->bodies = $bodies; }
}
