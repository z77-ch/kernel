<?php

namespace Z77\Shared\Tree;

use Z77\Shared\Attributes\ImportRef;

/**
 * Standard storage + accessors for a {@see TreeNode}.
 *
 * Both properties are deliberately WITHOUT a `#[Clean]` attribute: they are
 * server-controlled (set by add/move logic, never trusted from the edit form),
 * which keeps tree integrity out of reach of a crafted request body.
 */
trait TreeNodeTrait
{
    /** order among siblings (same parent + scope); lower comes first */
    private int $sortKey = 0;

    /**
     * id of the parent node; null = top-level root. For a data import
     * (ADR-032) this is a foreign key onto the OWN entity class — `'self'`
     * resolves to whichever class uses the trait, so every tree entity gets
     * the correct ref declaration without repeating it.
     */
    #[ImportRef('self')]
    private ?int $parentId = null;

    public function getSortKey(): int { return $this->sortKey; }

    public function getParentId(): ?int { return $this->parentId; }

    public function setSortKey(int $sortKey): void { $this->sortKey = $sortKey; }

    public function setParentId(?int $parentId): void
    {
        $this->parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
    }
}
