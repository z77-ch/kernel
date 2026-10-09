<?php

namespace Z77\Shared\Listing;

/**
 * One column of a standard backend list (listing.md, owner 2026-10-08:
 * «ein Standard, der den gleichen Code dafür verwenden kann»): its title,
 * its grid track, whether it SORTS (a sort key the repository maps to an
 * ORDER BY) and whether it SEARCHES (a field name in the address, parsed
 * strictly — an unreadable value marks the field invalid and does not
 * narrow the list, so a typo never looks like «nothing found»).
 *
 * Three shapes:
 *
 *   - {@see search()} — a column with a magnifier: the title sorts when it
 *     has a sort key, the field `f_…` belongs to the list's GET form;
 *   - {@see plain()}  — a title only, sorting optional, no search;
 *   - {@see slot()}   — a cell without a title (the state icon, a checkbox,
 *     a row action), an `aria-label` for the reader.
 *
 * `priority` is the drop stage of `.be-list__table--drop` (css-backend.md
 * LIST-DROP-STAGES-001): '3' leaves first on a narrow screen, '2' next,
 * null stays. The list computes its three track lists from the columns
 * ({@see ListDefinition::tracks()}), so a stage can never drift from them.
 */
final class Column
{
    private const KEY = '/^f_[a-z][a-z0-9_]*$/';

    private function __construct(
        public readonly ?string $key,
        public readonly string $label,
        public readonly ?string $sort,
        public readonly string $width,
        public readonly bool $numeric,
        public readonly ?string $priority,
        public readonly ?\Closure $parser,
        public readonly ?string $inputMode,
        public readonly bool $descendingFirst,
        public readonly ?string $ariaLabel,
    ) {
        if ($key !== null && !preg_match(self::KEY, $key)) {
            throw new \InvalidArgumentException("A search field is named f_<name> — '{$key}' is not");
        }
        if ($priority !== null && !in_array($priority, ['2', '3'], true)) {
            throw new \InvalidArgumentException("A drop priority is '2' or '3' — '{$priority}' is not");
        }
        if (trim($width) === '') {
            throw new \InvalidArgumentException('A column needs a grid track');
        }
    }

    /**
     * A column that searches — and sorts when $sort is given. $parser reads
     * the typed value ({@see Parsers}); null keeps the text as typed. The
     * sort's FIRST direction: descending for numbers, dates and amounts
     * (newest / largest first), ascending for names.
     */
    public static function search(string $key, string $label, string $width, ?string $sort = null, ?\Closure $parser = null, bool $numeric = false, ?string $priority = null, bool $descendingFirst = false, ?string $inputMode = null): self
    {
        return new self($key, $label, $sort, $width, $numeric, $priority, $parser, $inputMode, $descendingFirst, null);
    }

    /** A column with a title, sorting optional, no search. */
    public static function plain(string $label, string $width, ?string $sort = null, bool $numeric = false, ?string $priority = null, bool $descendingFirst = false): self
    {
        return new self(null, $label, $sort, $width, $numeric, $priority, null, null, $descendingFirst, null);
    }

    /** A cell without a title — the state icon, a checkbox, a row action. */
    public static function slot(string $ariaLabel, string $width = '2rem', ?string $priority = null): self
    {
        return new self(null, '', null, $width, false, $priority, null, null, false, $ariaLabel);
    }

    public function isSearchable(): bool
    {
        return $this->key !== null;
    }

    public function isSortable(): bool
    {
        return $this->sort !== null;
    }

    public function isSlot(): bool
    {
        return $this->ariaLabel !== null;
    }
}
