<?php

namespace Z77\Shared\Services;

use Z77\Core\DI;
use Z77\Shared\Content\BlockRegistry;
use Z77\Shared\Content\ContentRenderer;
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
        private ContentRenderer $renderer
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

        return new self($repository, new ContentRenderer(BlockRegistry::assemble()));
    }

    /**
     * Returns the document only if it exists AND is active; null otherwise.
     *
     * Language fallback (ADR-013 / i18n.md): a document that does NOT exist in the
     * requested language falls back to the default-language document. An existing
     * but inactive document is a deliberate state and does NOT fall back.
     */
    public function find(string $slug, string $language): ?Content
    {
        $content = $this->repository->findBySlug($slug, $language);

        if ($content === null) {
            $default = DI::getI18n()->getDefaultLanguage();
            if ($language !== $default) {
                $content = $this->repository->findBySlug($slug, $default);
            }
        }

        return ($content !== null && $content->isActive()) ? $content : null;
    }

    /** Renders a document by slug to safe HTML; '' if missing or inactive. */
    public function render(string $slug, string $language): string
    {
        $content = $this->find($slug, $language);

        return $content !== null ? $this->renderer->render($content) : '';
    }
}
