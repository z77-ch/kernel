<?php

namespace Z77\Shared\Listing;

use Z77\Core\Http\RequestMode;

/**
 * A standard backend list, declared once (listing.md, owner 2026-10-08):
 * its columns, the default sort, the page size, and the EXTRA keys the
 * address carries beside the columns (a view, a scope flag). From it the
 * list reads its state out of the query ({@see read()}), computes its grid
 * tracks per drop stage ({@see tracks()}) and names its form, its inputs
 * and its fetch region — so a template renders the head and the search
 * form with the kernel's partials (`partials/listHead`, `partials/listFind`)
 * and writes only its rows.
 *
 * The reserved keys `sort`, `dir` and `page` are the list's own; a column
 * key is `f_…`; an extra is anything else, with its allowed values — the
 * first is the default (`'view' => ['open', 'overdue', 'all']`, a flag
 * `'all' => ['', '1']`).
 */
final class ListDefinition
{
    public const RESERVED = ['sort', 'dir', 'page'];

    /** Longest search value kept — what is kept is what is searched. */
    public const VALUE_LENGTH = 80;

    /** @var list<Column> */
    private array $columns;

    /** @var array<string, Column> search key → column */
    private array $byKey = [];

    /** @var array<string, bool> sort key → descending first */
    private array $sorts = [];

    /** @var array<string, list<string>> extra key → allowed values, the first is the default */
    private array $extras;

    /**
     * @param string $id       the list's name — the id of its search form, the prefix of its inputs (`{id}-{key}`), its fetch region (`{id}-list`)
     * @param list<Column> $columns
     * @param array<string, list<string>> $extras
     */
    public function __construct(
        public readonly string $id,
        array $columns,
        public readonly string $defaultSort,
        public readonly int $pageSize,
        array $extras = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
            throw new \InvalidArgumentException("A list id is kebab-case — '{$id}' is not");
        }
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('A page holds at least one row');
        }
        $this->columns = array_values($columns);
        foreach ($this->columns as $column) {
            if (!$column instanceof Column) {
                throw new \InvalidArgumentException('A list is made of Column objects');
            }
            if ($column->key !== null) {
                if (isset($this->byKey[$column->key])) {
                    throw new \InvalidArgumentException("Search field '{$column->key}' is declared twice");
                }
                $this->byKey[$column->key] = $column;
            }
            if ($column->sort !== null) {
                if (isset($this->sorts[$column->sort])) {
                    throw new \InvalidArgumentException("Sort '{$column->sort}' is declared twice");
                }
                $this->sorts[$column->sort] = $column->descendingFirst;
            }
        }
        if (!isset($this->sorts[$defaultSort])) {
            throw new \InvalidArgumentException("The default sort '{$defaultSort}' is no column's sort");
        }
        foreach ($extras as $key => $allowed) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*$/', $key) || in_array($key, self::RESERVED, true) || str_starts_with($key, 'f_')) {
                throw new \InvalidArgumentException("'{$key}' cannot be an extra key — reserved or shaped like a search field");
            }
            if (!is_array($allowed) || $allowed === [] || array_filter($allowed, static fn($v) => !is_string($v)) !== []) {
                throw new \InvalidArgumentException("Extra '{$key}' names its allowed values, the first being the default");
            }
        }
        $this->extras = $extras;
    }

    /** @return list<Column> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function column(string $key): Column
    {
        return $this->byKey[$key] ?? throw new \InvalidArgumentException("No search field '{$key}' on list '{$this->id}'");
    }

    /** @return list<string> the search field keys, in column order */
    public function searchKeys(): array
    {
        return array_keys($this->byKey);
    }

    /** @return list<string> */
    public function sorts(): array
    {
        return array_keys($this->sorts);
    }

    public function descendingFirst(string $sort): bool
    {
        return $this->sorts[$sort] ?? false;
    }

    /** @return array<string, list<string>> */
    public function extras(): array
    {
        return $this->extras;
    }

    public function extraDefault(string $key): string
    {
        return $this->extras[$key][0] ?? throw new \InvalidArgumentException("No extra '{$key}' on list '{$this->id}'");
    }

    /** @return list<string> every key the address may carry — what a controller reads */
    public function queryKeys(): array
    {
        return array_merge($this->searchKeys(), array_keys($this->extras), self::RESERVED);
    }

    public function inputId(string $key): string
    {
        return $this->id . '-' . $key;
    }

    public function region(): string
    {
        return $this->id . '-list';
    }

    /**
     * The grid tracks per drop stage (css-backend.md LIST-DROP-STAGES-001):
     * every column; without priority 3; without 3 and 2.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function tracks(): array
    {
        $at = fn(array $dropped): string => implode(' ', array_map(
            static fn(Column $c) => $c->width,
            array_filter($this->columns, static fn(Column $c) => !in_array($c->priority, $dropped, true))
        ));

        return [$at([]), $at(['3']), $at(['3', '2'])];
    }

    /** The `style` of `.be-list__table` — the three track lists as custom properties. */
    public function style(): string
    {
        [$all, $sm, $xs] = $this->tracks();

        return "--be-list-cols: {$all}; --be-list-cols-sm: {$sm}; --be-list-cols-xs: {$xs}";
    }

    /**
     * The state out of the query (`$_GET` shaped): an unknown sort falls
     * back to the default, an unknown direction to the sort's first, the
     * page to 1, an extra outside its values to its default; every search
     * value trimmed, cut to {@see VALUE_LENGTH} and parsed by its column.
     *
     * @param array<string, mixed> $query
     */
    public function read(array $query): ListState
    {
        $sort = is_string($query['sort'] ?? null) && isset($this->sorts[$query['sort']]) ? $query['sort'] : $this->defaultSort;
        $dir  = $query['dir'] ?? null;
        $descending = $dir === 'asc' ? false : ($dir === 'desc' ? true : $this->sorts[$sort]);
        $page = max(1, (int) ($query['page'] ?? 1));

        $values = $parsed = $invalid = [];
        foreach ($this->byKey as $key => $column) {
            $value = is_string($query[$key] ?? null) ? trim($query[$key]) : '';
            if ($value === '') {
                continue;
            }
            $value        = mb_substr($value, 0, self::VALUE_LENGTH);
            $values[$key] = $value;
            $result       = $column->parser === null ? $value : ($column->parser)($value);
            if ($result === null) {
                $invalid[$key] = true;
            } else {
                $parsed[$key] = $result;
            }
        }
        $extras = [];
        foreach ($this->extras as $key => $allowed) {
            $value        = $query[$key] ?? null;
            $extras[$key] = is_string($value) && in_array($value, $allowed, true) ? $value : $allowed[0];
        }

        return new ListState($this, $sort, $descending, $page, $values, $parsed, $invalid, $extras);
    }

    /**
     * The state out of a request — every key of {@see queryKeys()} through
     * `getGetParameter()` (Rule 4: HTTP input through `Request` only).
     */
    public function readRequest(object $request): ListState
    {
        $query = [];
        foreach ($this->queryKeys() as $key) {
            $query[$key] = $request->getGetParameter($key);
        }

        return $this->read($query);
    }

    /**
     * Whether the list is asked for ALONE — a fetch of its region (sort,
     * page, search — core.js «fetch regions»): the action then leaves the
     * page's other slots (a toolbar) out.
     */
    public static function isFetch(object $request): bool
    {
        return method_exists($request, 'getMode') && $request->getMode() === RequestMode::Fetch;
    }
}
