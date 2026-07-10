<?php

namespace Z77\Shared\Tree;

/**
 * A node in a self-referential tree: it points at its parent via `parentId`
 * (null = top-level root) and orders itself among its siblings via `sortKey`.
 *
 * Any entity that implements this interface can reuse the generic tree
 * operations in {@see TreeService} (children, sorting, sibling grouping,
 * move/reorder, cycle detection). The {@see TreeNodeTrait} provides the
 * standard `parentId` / `sortKey` storage and accessors.
 *
 * Both `parentId` and `sortKey` are server-controlled — they express tree
 * integrity and must never be trusted from a request body.
 *
 * Driver-agnostic: a File-backed entity gets the standard storage from
 * {@see TreeNodeTrait}; a Doctrine/ORM entity implements this interface directly
 * with ORM-mapped `parentId` / `sortKey` columns (a scalar self-referential FK,
 * or an adapter over a `#[ORM\ManyToOne] $parent`). {@see TreeService} never
 * touches persistence — it only mutates these scalars and hands the changed
 * nodes back to the caller to persist — so the same algorithms serve any driver.
 */
interface TreeNode
{
    public function getId(): ?int;

    public function getParentId(): ?int;

    public function setParentId(?int $parentId): void;

    public function getSortKey(): int;

    public function setSortKey(int $sortKey): void;
}
