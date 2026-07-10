<?php

namespace Z77\Shared\Tree;

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

    /** id of the parent node; null = top-level root */
    private ?int $parentId = null;

    public function getSortKey(): int { return $this->sortKey; }

    public function getParentId(): ?int { return $this->parentId; }

    public function setSortKey(int $sortKey): void { $this->sortKey = $sortKey; }

    public function setParentId(?int $parentId): void
    {
        $this->parentId = ($parentId !== null && $parentId > 0) ? $parentId : null;
    }
}
