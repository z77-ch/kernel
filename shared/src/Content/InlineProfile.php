<?php

namespace Z77\Shared\Content;

/**
 * What inline formatting ONE field allows — built from its schema descriptor
 * (ADR-044): `inline` lists the features, `links` narrows link targets and
 * switches localisation of page links.
 *
 *   ['key' => 'copy', 'kind' => 'textarea',
 *    'inline' => ['bold', 'link', 'break'],
 *    'links'  => ['targets' => ['page', 'action'], 'localize' => true, 'newTab' => false]]
 *
 * `newTab` opens EXTERNAL links (http/https) in a new tab (target="_blank"
 * rel="noopener"); page, media, mailto and tel links never.
 *
 * A descriptor without `inline` yields the plain-text profile: everything is
 * escaped, nothing is formatted. Only the schema-aware read path
 * ({@see ContentView}) uses profiles; InlineMarkdown without a profile keeps its
 * legacy behaviour.
 */
final class InlineProfile
{
    public const FEATURES = ['bold', 'italic', 'link', 'break'];
    public const TARGETS  = ['page', 'media', 'external', 'mailto', 'tel', 'action'];

    /** Link targets allowed when a field enables `link` without narrowing them. */
    private const DEFAULT_TARGETS = ['page', 'media', 'external', 'mailto', 'tel'];

    /**
     * @param list<string> $features  subset of FEATURES
     * @param list<string> $targets   subset of TARGETS
     * @param array<string, array{href?:string, attributes?:array<string,string>}> $actions
     *        project actions (name ⇒ fallback href + attributes), from `contentActions`
     * @param (\Closure(string):string)|null $localizer  maps a canonical page path to the
     *        document language's URL (localizedUrl); used only when $localize is true
     * @param bool $newTab  external links open in a new tab
     */
    public function __construct(
        private array $features = [],
        private array $targets = self::DEFAULT_TARGETS,
        private bool $localize = false,
        private array $actions = [],
        private ?\Closure $localizer = null,
        private bool $newTab = false
    ) {
        $this->features = array_values(array_intersect($features, self::FEATURES));
        $this->targets  = array_values(array_intersect($targets, self::TARGETS));
    }

    /** Plain text: no formatting at all. */
    public static function plain(): self
    {
        return new self([]);
    }

    /**
     * @param array<string, mixed> $descriptor one field descriptor from BlockRenderer::schema()
     * @param array<string, array{href?:string, attributes?:array<string,string>}> $actions
     */
    public static function fromDescriptor(array $descriptor, array $actions = [], ?\Closure $localizer = null): self
    {
        $features = is_array($descriptor['inline'] ?? null) ? $descriptor['inline'] : [];
        $links    = is_array($descriptor['links'] ?? null) ? $descriptor['links'] : [];
        $targets  = is_array($links['targets'] ?? null) ? $links['targets'] : self::DEFAULT_TARGETS;

        return new self($features, $targets, (bool)($links['localize'] ?? false), $actions, $localizer, (bool)($links['newTab'] ?? false));
    }

    public function allows(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function allowsTarget(string $target): bool
    {
        return $this->allows('link') && in_array($target, $this->targets, true);
    }

    /** @return list<string> */
    public function features(): array
    {
        return $this->features;
    }

    public function localize(): bool
    {
        return $this->localize;
    }

    public function newTab(): bool
    {
        return $this->newTab;
    }

    /** @return array{href?:string, attributes?:array<string,string>}|null */
    public function action(string $name): ?array
    {
        $action = $this->actions[$name] ?? null;
        return is_array($action) ? $action : null;
    }

    public function localizePath(string $path): string
    {
        return ($this->localize && $this->localizer !== null) ? ($this->localizer)($path) : $path;
    }
}
