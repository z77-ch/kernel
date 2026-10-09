<?php
/**
 * Pager of a long list — financial's reports and journal list, debtor's
 * invoice list (`.be-pagination`, server links `?page=`): previous, the
 * pages, next, and «n Zeilen, Seite x von y». A shared partial of the kernel
 * (`$this->partial('partials/pager', […], 'Z77\\Shared')`) since P3 part 3 —
 * it moved out of module-financial when a second module paged (Rule 8). The
 * reports use it without any script; a list inside a fetch region sets
 * `regionLinks`, and core.js then reloads only that region — the links stay
 * plain links underneath.
 * Screen only (`.be-noprint`) — every printed page is the page on screen.
 *
 * @var \Z77\Shared\Paging\Paging $paging
 * @var callable $pageLink  page number → URL
 * @var string $unit         «Zeilen» / «Buchungen» / «Dokumente»
 * @var bool   $regionLinks  the links reload only the surrounding fetch region (core.js)
 */
$region = !empty($regionLinks) ? ' data-fetch-region-link' : '';
if ($paging->pageCount < 2) { return; }
$window = 3;
?>
<nav class="be-pagination be-noprint" aria-label="Seiten">
    <a<?= $region ?> class="be-pagination__link<?= $paging->page === 1 ? ' be-pagination__link--disabled' : '' ?>" href="<?= e($pageLink(max(1, $paging->page - 1))) ?>" aria-label="Vorherige Seite">‹</a>
    <?php for ($p = 1; $p <= $paging->pageCount; $p++): ?>
        <?php if ($p === 1 || $p === $paging->pageCount || abs($p - $paging->page) <= $window): ?>
        <a<?= $region ?> class="be-pagination__link<?= $p === $paging->page ? ' be-pagination__link--active' : '' ?>" href="<?= e($pageLink($p)) ?>"<?= $p === $paging->page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
        <?php elseif (abs($p - $paging->page) === $window + 1): ?>
        <span class="be-pagination__ellipsis">…</span>
        <?php endif; ?>
    <?php endfor; ?>
    <a<?= $region ?> class="be-pagination__link<?= $paging->page === $paging->pageCount ? ' be-pagination__link--disabled' : '' ?>" href="<?= e($pageLink(min($paging->pageCount, $paging->page + 1))) ?>" aria-label="Nächste Seite">›</a>
    <span class="be-pagination__count"><?= $paging->total ?> <?= e($unit) ?> · Seite <?= $paging->page ?> von <?= $paging->pageCount ?></span>
</nav>
