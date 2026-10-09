<?php

namespace Z77\Shared\Paging;

/**
 * One page of a long list (a report, the journal, the invoice list): the
 * requested page clamped into 1…pageCount (a page past the last is
 * reported, see `isBeyondLast()`), and the offset the SQL starts at. Server
 * links (`?page=`), no JavaScript (the css-backend rule for v2 lists).
 *
 * Lived in module-financial (`Reports\Paging`) until a screen of ANOTHER
 * module paged (module-debtor's invoice list, P3 part 3) — it moved here
 * with the pager partial (`partials/pager`, namespace `Z77\Shared`), so both
 * modules use one building block without one requiring the other (Rule 8).
 */
final class Paging
{
    public readonly int $page;
    public readonly int $pageCount;

    public function __construct(public readonly int $requested, public readonly int $pageSize, public readonly int $total)
    {
        if ($pageSize < 1) {
            throw new \InvalidArgumentException('A page holds at least one row');
        }
        $this->pageCount = max(1, intdiv($total + $pageSize - 1, $pageSize));
        $this->page      = min(max(1, $requested), $this->pageCount);
    }

    /** A page past the last was asked for (`?page=99`) and the last is shown instead — the screen says so. */
    public function isBeyondLast(): bool
    {
        return $this->requested > $this->pageCount;
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
