<?php

namespace Z77\Core\Libraries\Seo;

/**
 * The canonical/hreflang set for the document head, built on first READ.
 *
 * AbstractBaseController::html() hands every page a `$seo`, but only the
 * layouts that print canonical/hreflang (the frontend head partials) ever read
 * it. Building it needs the configured origin (Request::getBaseUrl()), which
 * throws on an installation without `canonicalBaseUrl` (SEC-005, ADR-030
 * point 4). Built eagerly, that throw took down every page — the backend and
 * the first-run setup included, i.e. exactly the surface an operator needs to
 * fill the value in (found in the P2 exit check, 2026-09-22).
 *
 * Deferred, the rule stays intact where it matters: a page that prints the
 * canonical still fails loudly without the origin — it never falls back to the
 * Host header — and a page that prints none is unaffected.
 *
 * Array access keeps the templates' shape (`$seo['canonical']`,
 * `foreach ($seo['alternates'] as …)`), so project overrides of the head
 * partials keep working unchanged. Read-only.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class SeoLinks implements \ArrayAccess
{
    /** @var (\Closure(): array{canonical: string, alternates: list<array{hreflang: string, url: string}>})|null */
    private ?\Closure $build;

    /** @var array{canonical: string, alternates: list<array{hreflang: string, url: string}>}|null */
    private ?array $links = null;

    /**
     * @param \Closure(): array{canonical: string, alternates: list<array{hreflang: string, url: string}>} $build
     */
    public function __construct(\Closure $build)
    {
        $this->build = $build;
    }

    /** @return array{canonical: string, alternates: list<array{hreflang: string, url: string}>} */
    private function toArray(): array
    {
        if ($this->links === null) {
            $this->links = ($this->build)();
            $this->build = null;
        }
        return $this->links;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new \LogicException('SeoLinks is read-only.');
    }

    public function offsetUnset(mixed $offset): never
    {
        throw new \LogicException('SeoLinks is read-only.');
    }
}
