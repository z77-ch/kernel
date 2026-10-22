<?php
/**
 * The head of a standard list (listing.md) — one cell per column of the
 * definition, in order:
 *
 *   - a searching column is a `.be-list__find`: the title sorts (a link
 *     reloading the region) when the column has a sort, the magnifier
 *     beside it opens the column's search pop-up (`partials/listFindPop`,
 *     the HTML popover — no JavaScript), whose field belongs to the list's
 *     GET form (`partials/listFind`);
 *   - a plain column is a title, a sort link when it sorts;
 *   - a slot is an empty cell with an `aria-label`.
 *
 * A row cell that should open its column's search is a `<label for="…">`
 * of the magnifier button — `$definition->inputId($key)`. A kernel partial, namespace
 * `Z77\Shared`; the classes are module-backend's `_list.scss`.
 *
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var string $action                the list URL the sort links point at
 * @var array<string, string> $keep   more query keys every link carries — optional
 */
$keep  = $keep ?? [];
$links = static fn(string $query): string => $action . '?' . ltrim(http_build_query($keep) . '&' . $query, '&');
?>
<div class="be-list__head">
    <?php foreach ($definition->columns() as $column): ?>
    <?php $priority = $column->priority !== null ? ' data-priority="' . e($column->priority) . '"' : ''; ?>
    <?php if ($column->isSlot()): ?>
    <span class="be-list__col" aria-label="<?= e((string) $column->ariaLabel) ?>"<?= $priority ?>></span>
    <?php elseif ($column->isSearchable()): ?>
    <?php $key = (string) $column->key; $id = $definition->inputId($key); ?>
    <span class="be-list__col be-list__find<?= $column->numeric ? ' be-list__col--num' : '' ?><?= $state->value($key) !== '' ? ' be-list__find--active' : '' ?><?= $state->isInvalid($key) ? ' be-list__find--invalid' : '' ?>"<?= $priority ?>>
        <?php if ($column->isSortable()): ?>
        <a class="be-list__sort" data-fetch-region-link href="<?= e($links($state->sortQuery((string) $column->sort))) ?>"<?= ($d = $state->sortDirection((string) $column->sort)) !== null ? ' data-sort="' . $d . '"' : ' data-sort' ?>><?= e($column->label) ?></a>
        <?php else: ?>
        <span class="be-list__find-label"><?= e($column->label) ?></span>
        <?php endif; ?>
        <?= $this->partial('partials/listFindPop', ['id' => $id, 'key' => $key, 'label' => $column->label, 'form' => $definition->id, 'value' => $state->value($key), 'invalid' => $state->isInvalid($key), 'inputMode' => $column->inputMode], 'Z77\Shared') ?>
    </span>
    <?php elseif ($column->isSortable()): ?>
    <span class="be-list__col<?= $column->numeric ? ' be-list__col--num' : '' ?>"<?= $priority ?>><a class="be-list__sort" data-fetch-region-link href="<?= e($links($state->sortQuery((string) $column->sort))) ?>"<?= ($d = $state->sortDirection((string) $column->sort)) !== null ? ' data-sort="' . $d . '"' : ' data-sort' ?>><?= e($column->label) ?></a></span>
    <?php else: ?>
    <span class="be-list__col<?= $column->numeric ? ' be-list__col--num' : '' ?>"<?= $priority ?>><?= e($column->label) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
