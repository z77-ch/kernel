<?php

namespace Z77\Shared\Services;

use Z77\Core\DI;
use Z77\Shared\Content\BlockRegistry;
use Z77\Shared\Content\ContentExtensions;
use Z77\Shared\Content\ContentRenderer;
use Z77\Shared\Content\ContentView;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;

/**
 * Content access for display: load a document by slug, gate on `active`, render
 * it to safe HTML. The active-gate lives here, in exactly one place — the
 * frontend always goes through this; the backend editor reads the repository
 * directly (it must see inactive documents).
 *
 * NOT a DI singleton: this is consumption, not framework infrastructure, and its
 * only collaborators are content-specific. The consuming controller builds it
 * on demand via {@see create()} — nothing is registered in the container. See
 * adr-012.
 */
final class ContentService
{
    public function __construct(
        private ContentRepository $repository,
        private ContentRenderer $renderer,
        private ?BlockRegistry $registry = null,
        private ?ContentExtensions $extensions = null
    ) {}

    /**
     * On-demand factory: composes the service from DI infrastructure (the
     * entity manager) + the assembled block registry. Build it once at the
     * consumption boundary (the controller) and reuse for multiple renders —
     * do not call per block/slug.
     */
    public static function create(): self
    {
        $repository = DI::getUnifiedEntityManager()->getRepository(Content::class);

        $registry   = BlockRegistry::assemble();

        return new self($repository, new ContentRenderer($registry), $registry, ContentExtensions::assemble());
    }

    /**
     * Returns the document only if it exists AND is active; null otherwise.
     *
     * Language fallback (ADR-013 / i18n.md): a document that does NOT exist in the
     * requested language falls back to the default-language document. An existing
     * but inactive document is a deliberate state and does NOT fall back.
     *
     * Variant (ADR-044 addendum): with a $variant key the lookup order is
     * variant → live in the requested language, then variant → live in the
     * default language. A set only has to contain the documents it changes.
     * Callers pass {@see \Z77\Shared\Content\ContentPreview::key()} explicitly;
     * without it (null) only live copies are read.
     */
    public function find(string $slug, string $language, ?string $variant = null): ?Content
    {
        $languages = [$language];
        $default   = DI::getI18n()->getDefaultLanguage();
        if ($language !== $default) {
            $languages[] = $default;
        }

        $variants = ($variant !== null && $variant !== '') ? [$variant, ''] : [''];

        foreach ($languages as $lang) {
            foreach ($variants as $key) {
                $content = $this->repository->findBySlug($slug, $lang, $key);
                if ($content !== null) {
                    return $content->isActive() ? $content : null;
                }
            }
        }

        return null;
    }

    /**
     * Schema-aware view of a document (ADR-044) — same gate and language fallback
     * as find(); null if missing or inactive. Page links in `localize` fields are
     * localised to the document's language via localizedUrl().
     */
    public function view(string $slug, string $language, ?string $variant = null): ?ContentView
    {
        $content = $this->find($slug, $language, $variant);
        if ($content === null) {
            return null;
        }

        $registry   = $this->registry   ?? BlockRegistry::assemble();
        $extensions = $this->extensions ?? ContentExtensions::assemble();

        return new ContentView(
            $content,
            $registry->schemas(),
            $extensions->actions(),
            static fn(string $path, string $lang): string => localizedUrl($path, $lang)
        );
    }

    /** Renders a document by slug to safe HTML; '' if missing or inactive. */
    public function render(string $slug, string $language, ?string $variant = null): string
    {
        $content = $this->find($slug, $language, $variant);

        return $content !== null ? $this->renderer->render($content) : '';
    }
}
