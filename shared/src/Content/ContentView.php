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
