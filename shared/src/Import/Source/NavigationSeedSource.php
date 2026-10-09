<?php

namespace Z77\Shared\Import\Source;

use Z77\Shared\Entities\Navigation;
use Z77\Shared\Import\ImportSource;
use Z77\Shared\Import\NavigationSeeds;

/**
 * The vendor source with the packages' navigation seeds folded in (ADR-050 §4): the
 * Navigation record set becomes the kernel's `navigation.default.json` (if the inner
 * source carries it — its ids stay, the alias and metadata defaults reference them)
 * PLUS the union of all `navigation.d` files, converted to in-source records
 * ({@see NavigationSeeds::toRecords()}). `parent_key` / `ref_key` thereby become
 * in-source refs, which the planner resolves against the TARGET through its identity
 * matches — a module entry under a kernel group plans as `new` under the right parent.
 *
 * An entry whose parent package is not installed is left out (the installer names it).
 */
final class NavigationSeedSource implements ImportSource
{
    /** @var array<class-string, list<array<string, mixed>>> */
    private array $sets;

    /** @param list<string> $seedFiles */
    public function __construct(private readonly ImportSource $inner, array $seedFiles)
    {
        $this->sets = $inner->recordSets();

        $base      = $this->sets[Navigation::class] ?? [];
        $converted = NavigationSeeds::toRecords(NavigationSeeds::read($seedFiles), $base);

        $this->sets[Navigation::class] = array_merge($base, $converted['records']);
    }

    public function recordSets(): array { return $this->sets; }

    public function label(): string { return $this->inner->label(); }
}
