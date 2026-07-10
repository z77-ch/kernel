<?php

namespace Z77\Shared\Tree;

/**
 * Generic, stateless operations over a self-referential tree of {@see TreeNode}s.
 *
 * The service holds no data — every method takes the node collection it works
 * on. This keeps it free of any caching / persistence concern: consumers read
 * the nodes from wherever they like (repository, cache) and persist whatever the
 * write methods hand back. The same instance can therefore serve any entity.
 *
 * ## Scope
 *
 * Siblings are nodes that share the same `parentId` AND the same *scope*. For
 * inner nodes the `parentId` alone partitions them; for top-level roots
 * (`parentId === null`) an optional scope partitions the forest into independent
 * root groups. The scope of a node is derived by the `scopeOf` callback passed
 * to the constructor (default: a single, null scope — one root group).
 *
 * Example: Navigation tree-roots are grouped by their render-slot slug, so its
 * service is constructed with `new TreeService(fn(Navigation $n) => $n->getSlot())`.
 * An entity whose roots form a single forest uses the default (no scope).
 *
 * ## Mechanics vs. policy
 *
 * This service only performs the *mechanical* part of a move (compute the
 * sibling group, splice at an index, renumber densely). Entity-specific *policy*
 * — cycle guards, what scope/tag a node inherits, cross-scope rules — stays with
 * the caller and is applied to the node BEFORE the reorder call.
 */
final class TreeService
{
    /** @var callable(TreeNode):mixed */
    private $scopeOf;

    /**
     * @param (callable(TreeNode):mixed)|null $scopeOf derives a node's root-group
     *        scope; null = every root shares one (null) scope.
     */
    public function __construct(?callable $scopeOf = null)
    {
        $this->scopeOf = $scopeOf ?? static fn(): mixed => null;
    }

    public function scopeOf(TreeNode $node): mixed
    {
        return ($this->scopeOf)($node);
    }

    /**
     * Sorts nodes by `sortKey`, with `id` as a stable tie-breaker. Single
     * ordering authority — display order is driven by the stored `sortKey`,
     * never by array/file position (stable across an ORM migration).
     *
     * @param TreeNode[] $nodes
     * @return TreeNode[]
     */
    public function sort(array $nodes): array
    {
        $nodes = array_values($nodes);
        usort(
            $nodes,
            static fn(TreeNode $a, TreeNode $b) =>
                [$a->getSortKey(), $a->getId()] <=> [$b->getSortKey(), $b->getId()]
        );
        return $nodes;
    }

    /**
     * Builds an id => node lookup. Used by the chain-walking helpers
     * ({@see isDescendantOf}, {@see rootOf}).
     *
     * @param TreeNode[] $nodes
     * @return array<int, TreeNode>
     */
    public function index(array $nodes): array
    {
        $index = [];
        foreach ($nodes as $node) {
            $id = $node->getId();
            if ($id !== null) {
                $index[$id] = $node;
            }
        }
        return $index;
    }

    /**
     * Children of `$parentId`, sorted. For roots (`$parentId === null`) the
     * `$scope` additionally partitions the forest; for inner nodes the scope is
     * irrelevant (the parent already partitions them).
     *
     * @param TreeNode[] $nodes
     * @return TreeNode[]
     */
    public function children(array $nodes, ?int $parentId, mixed $scope = null): array
    {
        return $this->sort(array_filter(
            $nodes,
            fn(TreeNode $n) => $this->inGroup($n, $parentId, $scope)
        ));
    }

    /**
     * Members of an explicit sibling group (`$parentId` + `$scope`), excluding
     * `$excludeId`. Unsorted — callers that splice/renumber re-sort themselves.
     *
     * @param TreeNode[] $nodes
     * @return TreeNode[]
     */
    public function siblingGroup(array $nodes, ?int $parentId, mixed $scope, ?int $excludeId = null): array
    {
        $members = [];
        foreach ($nodes as $n) {
            if ($excludeId !== null && $n->getId() === $excludeId) {
                continue;
            }
            if ($this->inGroup($n, $parentId, $scope)) {
                $members[] = $n;
            }
        }
        return $members;
    }

    /**
     * The sibling group `$node` currently belongs to (its `parentId` + derived
     * scope), excluding `$excludeId`.
     *
     * @param TreeNode[] $nodes
     * @return TreeNode[]
     */
    public function siblingGroupOf(array $nodes, TreeNode $node, ?int $excludeId = null): array
    {
        return $this->siblingGroup($nodes, $node->getParentId(), $this->scopeOf($node), $excludeId);
    }

    private function inGroup(TreeNode $n, ?int $parentId, mixed $scope): bool
    {
        return $parentId !== null
            ? $n->getParentId() === $parentId
            : ($n->getParentId() === null && $this->scopeOf($n) === $scope);
    }

    /**
     * Next free `sortKey` for a node appended at the end of its sibling group.
     *
     * @param TreeNode[] $nodes
     */
    public function nextSortKey(array $nodes, TreeNode $node): int
    {
        $max = -1;
        foreach ($this->siblingGroupOf($nodes, $node, $node->getId()) as $member) {
            $max = max($max, $member->getSortKey());
        }
        return $max + 1;
    }

    /**
     * Assigns dense `sortKey`s `0..n` over the group in sorted order.
     *
     * @param TreeNode[] $group
     * @return TreeNode[] the renumbered group (sorted) — persist these
     */
    public function renumber(array $group): array
    {
        $group = $this->sort($group);
        foreach ($group as $i => $member) {
            $member->setSortKey($i);
        }
        return $group;
    }

    /**
     * Mechanical reorder: splices `$node` into its (already set) target sibling
     * group at `$newIndex` and renumbers the group densely. `$node` MUST already
     * carry its target `parentId` and scope (the caller's policy step).
     *
     * Renumbering the *old* group (when the node changed groups) is the caller's
     * job via {@see siblingGroup} + {@see renumber}, because only the caller
     * knows the old scope after it mutated the node.
     *
     * @param TreeNode[] $nodes
     * @return TreeNode[] the changed nodes (incl. `$node`) — persist these
     */
    public function reorderInto(array $nodes, TreeNode $node, int $newIndex): array
    {
        $group = $this->sort($this->siblingGroupOf($nodes, $node, $node->getId()));
        $newIndex = max(0, min($newIndex, count($group)));
        array_splice($group, $newIndex, 0, [$node]);
        foreach ($group as $i => $member) {
            $member->setSortKey($i);
        }
        return $group;
    }

    /**
     * Iterates all descendants of `$root` depth-first, yielding each with its
     * depth (root's direct children = depth 0).
     *
     * @param TreeNode[] $nodes
     * @return \Generator<int, array{node: TreeNode, depth: int}>
     */
    public function descendants(array $nodes, TreeNode $root): \Generator
    {
        yield from $this->descend($nodes, $root, 0);
    }

    /**
     * @param TreeNode[] $nodes
     * @return \Generator<int, array{node: TreeNode, depth: int}>
     */
    private function descend(array $nodes, TreeNode $node, int $depth): \Generator
    {
        foreach ($this->children($nodes, $node->getId()) as $child) {
            yield ['node' => $child, 'depth' => $depth];
            yield from $this->descend($nodes, $child, $depth + 1);
        }
    }

    /**
     * True when `$candidate` sits inside `$root`'s subtree (any depth) — i.e.
     * moving `$root` under `$candidate` would create a cycle. Walks UP from
     * `$candidate` via `parentId`; reaching `$root` means it is a descendant.
     *
     * @param array<int, TreeNode> $index id => node (from {@see index})
     */
    public function isDescendantOf(array $index, TreeNode $candidate, TreeNode $root): bool
    {
        $current = $candidate;
        $guard   = 50;
        while ($current !== null && $guard-- > 0) {
            if ($current->getId() === $root->getId()) {
                return true;
            }
            $pid     = $current->getParentId();
            $current = $pid !== null ? ($index[$pid] ?? null) : null;
        }
        return false;
    }

    /**
     * Walks up from `$node` to its tree-root (the ancestor with `parentId`
     * null). Returns `$node` itself when it is already a root or the chain
     * breaks on missing data.
     *
     * @param array<int, TreeNode> $index id => node (from {@see index})
     */
    public function rootOf(array $index, TreeNode $node): TreeNode
    {
        $current = $node;
        $guard   = 50;
        while ($guard-- > 0) {
            $pid = $current->getParentId();
            if ($pid === null) {
                return $current;
            }
            $next = $index[$pid] ?? null;
            if ($next === null) {
                return $current;
            }
            $current = $next;
        }
        return $current;
    }

    /**
     * True when no node in `$nodes` has `$node` as its parent — i.e. `$node` is a
     * leaf of the tree (the deepest group level). Policy-free query; any
     * "elements may only attach to a leaf group" rule lives in the domain validator.
     *
     * @param TreeNode[] $nodes
     */
    public function isLeaf(array $nodes, TreeNode $node): bool
    {
        $id = $node->getId();
        if ($id === null) {
            return true;
        }
        foreach ($nodes as $n) {
            if ($n->getParentId() === $id) {
                return false;
            }
        }
        return true;
    }
}
