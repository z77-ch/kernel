<?php

namespace Z77\Shared\Content;

use Z77\Core\DI;
use Z77\Shared\Services\ContentService;

/**
 * The content document of a page, for the current request — the entry point a
 * frontend controller (or a service it calls) uses for designed pages
 * (ADR-044). It adds the three things every such page needs and that are easy
 * to forget when ContentService::view() is called directly:
 *
 *   - the request language (with ContentService's default-language fallback);
 *   - the content preview: `?preview=<key>` renders the variant set
 *     (ContentPreview::key(), ADR-044 addendum) — called directly, the service
 *     ignores previews, silently;
 *   - a loud failure: a page whose document is missing or inactive throws. A
 *     designed page without its text is a deploy error (the documents go to
 *     the shared data/content/ BEFORE the release), and an exception says so
 *     where an empty page would hide it.
 *
 * ContentService stays request-free; this class is where the request is read.
 * Each call builds its own ContentService (ADR-012: consumer-built, not DI).
 */
final class PageContent
{
    private function __construct() {}

    /**
     * @throws \RuntimeException when the document is missing or inactive in the
     *         request language and in the default language
     */
    public static function view(string $slug): ContentView
    {
        $language = DI::getRequest()->getLanguage();
        $view     = ContentService::create()->view($slug, $language, ContentPreview::key());
        if ($view === null) {
            throw new \RuntimeException("Content document '{$slug}' ({$language}) is missing or inactive in data/content/.");
        }

        return $view;
    }
}
