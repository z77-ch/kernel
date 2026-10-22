<?php

namespace Z77\Shared\Content;

use Z77\Core\DI;
use Z77\Shared\Services\ContentService;

/**
 * The content document of a page, for the current request — the entry point a
 * frontend controller (or a service it calls) uses for designed pages
 * (ADR-044). It adds the things every such page needs and that are easy
 * to forget when ContentService::view() is called directly:
 *
 *   - the request language (with ContentService's default-language fallback);
 *   - the content preview: `?preview=<key>` renders the variant set
 *     (ContentPreview::key(), ADR-044 addendum) — called directly, the service
 *     ignores previews, silently;
 *   - a loud failure: a page whose document is missing or inactive throws
 *     (view()). A designed page without its text is a deploy error (the
 *     documents go to the shared data/content/ BEFORE the release), and an
 *     exception says so where an empty page would hide it. find() is the
 *     quiet variant for a page that may be deleted on purpose;
 *   - the page editor (ADR-045 §4): when PageEditing::active() says so (a
 *     user with at least `editor`, a full page, the switch «Seite bearbeiten»
 *     on) the view marks its slots — ContentView::editAttribute() then returns
 *     the marker, otherwise ''. The same decision adds content-edit.js
 *     (AbstractFrontendController).
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
        return self::find($slug) ?? throw new \RuntimeException(
            "Content document '{$slug}' (" . DI::getRequest()->getLanguage() . ') is missing or inactive in data/content/.'
        );
    }

    /**
     * Like view(), but null instead of the exception — for a page that may be
     * deleted on purpose (the frontend starter pages: delete the document and
     * the menu entry and the page is gone, the controller answers 404) and for a
     * layout element that renders without its text (the starter footer). Same
     * language, preview and page-editor handling as view().
     */
    public static function find(string $slug): ?ContentView
    {
        $preview = ContentPreview::key();
        $view    = ContentService::create()->view($slug, DI::getRequest()->getLanguage(), $preview);
        if ($view === null) {
            return null;
        }

        return PageEditing::active()
            ? $view->forEditor(ContentExtensions::assemble()->blueprint($slug), $preview)
            : $view;
    }
}
