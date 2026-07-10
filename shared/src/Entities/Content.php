<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Content\BlockView;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A slug-addressed, self-contained content document.
 *
 * Identity is (slug, language) — stored as one file per record in document mode
 * (data/content/<slug>.<language>.json). The body is an ordered array of
 * heterogeneous blocks ([{type, ...fields}, ...]); rendering is server-controlled
 * via the BlockRegistry (see Z77\Shared\Content). Not page-bound — a controller
 * composes a page by loading one or more documents by slug.
 */
#[Entity('file', 'content', invalidatesCache: true, perRecord: true, keyBy: ['slug', 'language'])]
class Content
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    #[Clean('slug')]
    private string $slug = '';

    #[Clean('ident')]
    private string $language = '';

    #[Clean('text')]
    private string $title = '';

    /**
     * Public visibility. false → editable in the backend but NOT rendered on the
     * frontend (work-in-progress content). The frontend gate lives in ContentService,
     * not here; the render path itself does not check it.
     */
    #[Clean('bool')]
    private bool $active = true;

    /**
     * Ordered list of blocks, each a map {type, ...type-specific fields}.
     * Block-level cleaning/validation is the editor's concern (Phase 3); on the
     * read path blocks come from the trusted file and are escaped at render time.
     * @var array<int, array<string, mixed>>
     */
    private array $blocks = [];

    public function getSlug(): string { return $this->slug; }
    public function getLanguage(): string { return $this->language; }
    public function getTitle(): string { return $this->title; }
    public function isActive(): bool { return $this->active; }
    public function getBlocks(): array { return $this->blocks; }

    // ── bespoke-template access (designer owns the markup) ──────────────────
    // The stream path uses getBlocks() + ContentRenderer; these expose the same
    // blocks as typed-ish views so a template can hand-place them. See content.md.

    /** First block of $type as a {@see BlockView}, or an empty null-object. */
    public function block(string $type): BlockView
    {
        foreach ($this->blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $type) {
                return new BlockView($block);
            }
        }
        return BlockView::empty();
    }

    /**
     * All blocks of $type, in document order.
     * @return array<int, BlockView>
     */
    public function blocks(string $type): array
    {
        $out = [];
        foreach ($this->blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $type) {
                $out[] = new BlockView($block);
            }
        }
        return $out;
    }

    /** True if at least one block of $type exists (gate a wrapper in the template). */
    public function has(string $type): bool
    {
        foreach ($this->blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === $type) {
                return true;
            }
        }
        return false;
    }

    public function setSlug(string $slug): void { $this->slug = $slug; }
    public function setLanguage(string $language): void { $this->language = $language; }
    public function setTitle(string $title): void { $this->title = $title; }
    public function setActive(bool $active): void { $this->active = $active; }

    /**
     * Accepts an array (from the JSON file) or a JSON-encoded string (from the
     * backend editor's blocks textarea). Invalid JSON → empty list; the validator
     * reports the parse error separately so the user gets feedback.
     */
    public function setBlocks(mixed $blocks): void
    {
        if (is_string($blocks)) {
            $blocks = $blocks === '' ? [] : json_decode($blocks, true);
        }
        $this->blocks = is_array($blocks) ? $blocks : [];
    }
}
