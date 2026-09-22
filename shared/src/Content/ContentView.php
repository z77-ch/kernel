<?php

namespace Z77\Shared\Content;

use Z77\Shared\Entities\Content;

/**
 * Schema-aware read access to one content document (ADR-044) — what a bespoke
 * template reads when the per-field formatting gate should apply.
 *
 *   $page = ContentService::create()->view('faq', $language);
 *   $page->keyed('intro')->text('title');   // escaped
 *   $page->keyed('intro')->html('body');     // only what the field's `inline` allows
 *
 * Page links in fields with `links.localize` are localised to the DOCUMENT's
 * language (which differs from the request language when ContentService fell
 * back to the default-language document).
 */
final class ContentView
{
    /**
     * The backend route of the slot editor (module-backend
     * ContentController::slotAction) — what a slot marker points at.
     */
    public const SLOT_EDITOR = '/backend/content/content/slot';

    /**
     * Set by {@see forEditor()} only: the slot labels of the page's blueprint
     * and the request's preview key. null = no markers (every visitor).
     *
     * @var array{labels: array<string, string>, preview: ?string}|null
     */
    private ?array $editing = null;

    /**
     * @param array<string, list<array<string, mixed>>> $schemas type ⇒ descriptors
     * @param array<string, array{href?:string, attributes?:array<string,string>}> $actions
     * @param (\Closure(string, string):string)|null $localizer (path, language) ⇒ URL
     */
    public function __construct(
        private Content $content,
        private array $schemas,
        private array $actions = [],
        private ?\Closure $localizer = null
    ) {}

    public function content(): Content
    {
        return $this->content;
    }

    public function title(): string
    {
        return $this->content->getTitle();
    }

    public function language(): string
    {
        return $this->content->getLanguage();
    }

    public function has(string $key): bool
    {
        return $this->block($key) !== null;
    }

    /** The block with $key, formatting gated by its type's schema; empty null-object if absent. */
    public function keyed(string $key): BlockView
    {
        $block = $this->block($key);
        if ($block === null) {
            return BlockView::empty();
        }

        $language  = $this->content->getLanguage();
        $localizer = $this->localizer === null
            ? null
            : fn(string $path): string => ($this->localizer)($path, $language);

        return new BlockView($block, true, $this->schemas[(string)($block['type'] ?? '')] ?? [], $this->actions, $localizer);
    }

    /**
     * A copy of this view whose {@see editAttribute()} marks the slots for the
     * page editor (ADR-045 §4). {@see PageContent} calls it — for a user with at
     * least `editor` on a full page, nobody else. Returns the view unchanged
     * (no markers) where nothing may be edited on the page:
     *   - the slug has no blueprint (the editor edits slots, not free blocks);
     *   - the shown document is a version, or the preview key is a version key
     *     (history is restored in the backend, not edited).
     */
    public function forEditor(?Blueprint $blueprint, ?string $preview): self
    {
        if ($blueprint === null || $this->content->isVersion()
            || ($preview !== null && ContentPreview::isVersionKey($preview))) {
            return $this;
        }

        $labels = [];
        foreach ($blueprint->slots() as $slot) {
            $labels[$slot['key']] = $slot['label'];
        }

        $copy          = clone $this;
        $copy->editing = ['labels' => $labels, 'preview' => $preview];

        return $copy;
    }

    /**
     * The marker a template prints on the root element of the markup that
     * renders slot $slot (ADR-045 §4):
     *
     *   <section class="intro"<?= raw($page->editAttribute('intro')) ?>>
     *
     * For an editor (see forEditor()): ` data-content-edit="<slot editor URL>"
     * data-content-edit-label="<slot label>"`, escaped, with a leading space.
     * For everyone else, and for a key that is not a slot of the blueprint: ''
     * — a visitor's HTML stays byte-identical.
     *
     * The URL names the document actually SHOWN (after language fallback and
     * preview: its slug, language and variant), the slot, and the request's
     * preview key — the slot editor decides from these where a save goes.
     */
    public function editAttribute(string $slot): string
    {
        $label = $this->editing['labels'][$slot] ?? null;
        if ($label === null) {
            return '';
        }

        $url = self::slotEditorUrl(
            $this->content->getSlug(),
            $this->content->getLanguage(),
            $this->content->getVariant(),
            $slot,
            $this->editing['preview']
        );

        return ' data-content-edit="' . self::attr($url) . '" data-content-edit-label="' . self::attr($label) . '"';
    }

    /**
     * The slot editor URL for one slot of one document. `variant` is always
     * present ('' = the live copy), `preview` only with a key.
     */
    public static function slotEditorUrl(string $slug, string $language, string $variant, string $slot, ?string $preview): string
    {
        $query = ['slug' => $slug, 'language' => $language, 'variant' => $variant, 'slot' => $slot];
        if ($preview !== null && $preview !== '') {
            $query['preview'] = $preview;
        }

        return self::SLOT_EDITOR . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function block(string $key): ?array
    {
        foreach ($this->content->getBlocks() as $block) {
            if (is_array($block) && (string)($block['key'] ?? '') === $key) {
                return $block;
            }
        }
        return null;
    }
}
