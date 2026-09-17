<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Attributes\ImportIdentity;
use Z77\Shared\Attributes\ImportRef;
use Z77\Shared\Traits\ArrayMappable;

/**
 * NavigationAlias
 *
 * Canonical entry URL of a {@see Navigation}. A plain URL→navigation mapping bound
 * via the FK `navigationId` — NOT a tree node. `path` holds the canonical
 * (default-language) entry path (e.g. `/home`, `/schweiz/stadt`). An alias matches
 * its EXACT path; only with `acceptsSlugs` does it also match as a prefix, the
 * remainder of the request URL then being runtime content slugs (never stored
 * here). See ADR-015 and its 2026-09-17 amendment.
 *
 * `id` is server-controlled (no setter) — assigned by the FileRepository on persist
 * and hydrated via reflection on load.
 */
// Import identity (ADR-032): the alias path is a true natural key — unique
// across all aliases by validator invariant. Deliberately NO near-match: a
// differing path IS a different alias (multiple per navigation are legal),
// never a rename to guess at.
#[Entity('file', 'framework/routing/navigation_aliases.json', invalidatesCache: true)]
#[ImportIdentity(['path'])]
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
    #[ImportRef(Navigation::class)]
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

    /**
     * false (default): the alias matches its exact path — `/kontakt/anything` is NOT
     * this page. true: the alias also matches as a prefix; the remainder reaches the
     * action via `Request::getSlugs()` — raw, never translated. The ACTION owns that
     * remainder: an unknown slug or a wrong slug count must end in a
     * NotFoundException, or the page serves unlimited URLs. Must be identical on all
     * aliases of one navigation (validator). ADR-015 amendment 2026-09-17.
     */
    #[Clean('bool')]
    private bool $acceptsSlugs = false;

    public function getId(): ?int { return $this->id; }
    public function getNavigationId(): ?int { return $this->navigationId; }
    public function getPath(): string { return $this->path; }
    public function isCanonical(): bool { return $this->isCanonical; }
    public function isActive(): bool { return $this->active; }
    public function acceptsSlugs(): bool { return $this->acceptsSlugs; }

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
    public function setAcceptsSlugs(bool $acceptsSlugs): void { $this->acceptsSlugs = $acceptsSlugs; }
}
