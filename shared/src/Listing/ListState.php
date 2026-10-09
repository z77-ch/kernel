<?php

namespace Z77\Shared\Listing;

use Z77\Shared\Paging\Paging;

/**
 * The state of a standard list as the address carries it (listing.md): the
 * sort and its direction, the page, the search values (raw, as typed, and
 * parsed), the invalid ones, and the extras (a view, a flag). The state IS
 * the address — a GET form, no JavaScript: bookmarkable, and every link of
 * the list (a sort, a page, a tab) keeps the rest of it ({@see query()}).
 * Built by {@see ListDefinition::read()}, plain data.
 */
final class ListState
{
    /**
     * @param array<string, string> $values   search key → the value as typed (trimmed, cut)
     * @param array<string, mixed>  $parsed   search key → the parsed value (absent when empty or invalid)
     * @param array<string, true>   $invalid  search key → the value could not be read
     * @param array<string, string> $extras   extra key → value (the default when absent or unknown)
     */
    public function __construct(
        public readonly ListDefinition $definition,
        public readonly string $sort,
        public readonly bool $descending,
        public readonly int $page,
        private readonly array $values,
        private readonly array $parsed,
        private readonly array $invalid,
        private readonly array $extras,
    ) {}

    /** The search value as typed — what the input shows again. */
    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /** The parsed search value, or null when the field is empty or invalid — what the repository gets. */
    public function parsed(string $key): mixed
    {
        return $this->parsed[$key] ?? null;
    }

    public function isInvalid(string $key): bool
    {
        return isset($this->invalid[$key]);
    }

    /** Any column search is set. */
    public function isActive(): bool
    {
        return $this->values !== [];
    }

    public function extra(string $key): string
    {
        return $this->extras[$key] ?? $this->definition->extraDefault($key);
    }

    /** A flag extra (`['', '1']`) is on. */
    public function flag(string $key): bool
    {
        return $this->extra($key) === '1';
    }

    /** @return array<string, string> */
    public function extras(): array
    {
        return $this->extras;
    }

    public function paging(int $total): Paging
    {
        return new Paging($this->page, $this->definition->pageSize, $total);
    }

    /**
     * The query string of this state with $changes applied: null, '' and
     * false remove a key, true is '1'. The page travels only when it IS the
     * change (a pager link) — every other link starts at page 1 again;
     * defaults are left out, so the plain list has a plain address.
     *
     * @param array<string, string|int|bool|null> $changes
     */
    public function query(array $changes = []): string
    {
        $state = $this->values + $this->extras + [
            'sort' => $this->sort,
            'dir'  => $this->descending ? 'desc' : 'asc',
            'page' => array_key_exists('page', $changes) ? (string) $this->page : '1',
        ];
        foreach ($changes as $key => $value) {
            $state[$key] = $value === null || $value === false ? '' : ($value === true ? '1' : (string) $value);
        }
        // Defaults out.
        foreach ($this->definition->extras() as $key => $allowed) {
            if (($state[$key] ?? '') === $allowed[0]) {
                $state[$key] = '';
            }
        }
        $sort = $state['sort'] !== '' ? $state['sort'] : $this->definition->defaultSort;
        if ($sort === $this->definition->defaultSort) {
            $state['sort'] = '';
        }
        if ($state['dir'] === ($this->definition->descendingFirst($sort) ? 'desc' : 'asc')) {
            $state['dir'] = '';
        }
        if ($state['page'] === '1') {
            $state['page'] = '';
        }

        return http_build_query(array_filter($state, static fn($v) => $v !== ''));
    }

    /** The query of a column title: the current sort flips its direction, another starts in its first. */
    public function sortQuery(string $sort): string
    {
        $descending = $this->sort === $sort ? !$this->descending : $this->definition->descendingFirst($sort);

        return $this->query(['sort' => $sort, 'dir' => $descending ? 'desc' : 'asc']);
    }

    /** The query without any search value — «Suche zurücksetzen». */
    public function resetQuery(): string
    {
        return $this->query(array_fill_keys($this->definition->searchKeys(), null));
    }

    /** `asc` / `desc` for the sorted column's `data-sort`, null for the others. */
    public function sortDirection(string $sort): ?string
    {
        return $this->sort === $sort ? ($this->descending ? 'desc' : 'asc') : null;
    }

    /**
     * The hidden fields of the search form: the extras and the sort that
     * are not the default — the column values travel as the inputs.
     *
     * @return array<string, string>
     */
    public function hiddenState(): array
    {
        $hidden = [];
        foreach ($this->extras as $key => $value) {
            if ($value !== $this->definition->extraDefault($key)) {
                $hidden[$key] = $value;
            }
        }
        if ($this->sort !== $this->definition->defaultSort) {
            $hidden['sort'] = $this->sort;
        }
        if ($this->descending !== $this->definition->descendingFirst($this->sort)) {
            $hidden['dir'] = $this->descending ? 'desc' : 'asc';
        }

        return $hidden;
    }
}
