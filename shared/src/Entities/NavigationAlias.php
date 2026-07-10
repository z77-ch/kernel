<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * NavigationAlias
 *
 * Canonical entry URL of a {@see Navigation}. A plain URL→navigation mapping bound
 * via the FK `navigationId` — NOT a tree node. `path` holds the canonical
 * (default-language) entry path (e.g. `/home`, `/schweiz/stadt`); the dynamic
 * remainder of a request URL is captured as runtime content slugs and is never
 * stored here. See ADR-015.
 *
 * `id` is server-controlled (no setter) — assigned by the FileRepository on persist
 * and hydrated via reflection on load.
 */
#[Entity('file', 'framework/routing/navigation_aliases.json', invalidatesCache: true)]
class NavigationAlias
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    /** FK to the {@see Navigation} this alias is the entry URL for. */
    #[Clean('int')]
    private ?int $navigationId = null;

    /** Canonical (default-language) entry path, e.g. `/home` or `/schweiz/stadt`. */
    #[Clean('slug')]
    private string $path = '';

    /**
     * The single public entry URL of a navigation is its canonical alias; further
     * (non-canonical) aliases may exist as additional reachable entry points.
     */
    #[Clean('bool')]
    private bool $isCanonical = true;

    #[Clean('bool')]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }
    public function getNavigationId(): ?int { return $this->navigationId; }
    public function getPath(): string { return $this->path; }
    public function isCanonical(): bool { return $this->isCanonical; }
    public function isActive(): bool { return $this->active; }

    public function setNavigationId(?int $navigationId): void
    {
        $this->navigationId = ($navigationId === null || $navigationId <= 0) ? null : $navigationId;
    }

    /** Normalizes to a single leading slash, no trailing slash (`/schweiz/stadt`). */
    public function setPath(string $path): void
    {
        $path = trim($path);
        $this->path = $path === '' ? '' : '/' . trim($path, '/');
    }

    public function setIsCanonical(bool $isCanonical): void { $this->isCanonical = $isCanonical; }
    public function setActive(bool $active): void { $this->active = $active; }
}
