<?php
/**
 * The search of ONE column of a standard list (listing.md, owner 2026-10-16: «ein richtiges
 * Popupfenster mit der Bezeichnung, nach was gesucht wird»): the magnifier in the column title
 * is a button that opens a small pop-up — «{Spalte} suchen», the field, «Suchen». The pop-up is
 * the HTML `popover` (top layer: never clipped by the list's scroll frame or a narrow column,
 * closed by Esc or a click outside; no JavaScript of its own — Rule 7). The field belongs to the
 * list's GET form through `form="…"`, so Enter and «Suchen» search on the server; inside a fetch
 * region the answer replaces the list, the pop-up with it.
 *
 * The BUTTON carries the column's input id (`ListDefinition::inputId()`): a row cell that is a
 * `<label for="…">` of it opens the pop-up (a label activates its button). The field itself is
 * `{id}-value`. An active search shows its value after the title; an unreadable one marks the
 * magnifier and the field invalid. A kernel partial, namespace `Z77\Shared`; the classes are
 * module-backend's `_list.scss`.
 *
 * @var string $id            the column's input id — the button's
 * @var string $key           the field name (`f_…`)
 * @var string $label         the column title
 * @var string $form          the id of the list's GET form
 * @var string $value         the value as typed ('' = no search)
 * @var bool $invalid         the value could not be read
 * @var string|null $inputMode
 */
$inputMode = $inputMode ?? null;
$pop       = $id . '-pop';
$field     = $id . '-value';
?>
<?php if ($value !== ''): ?>
<span class="be-list__find-value" title="<?= e($label) ?>: <?= e($value) ?>"><?= e($value) ?></span>
<?php endif; ?>
<button type="button" class="be-list__find-icon" id="<?= e($id) ?>" popovertarget="<?= e($pop) ?>" title="<?= e($label) ?> suchen" aria-label="<?= e($label) ?> suchen">
    <svg class="be-icon" width="12" height="12" aria-hidden="true"><use href="#icon-search"/></svg>
</button>
<div class="be-list__find-pop" id="<?= e($pop) ?>" popover>
    <label class="be-list__find-pop-label" for="<?= e($field) ?>"><?= e($label) ?> suchen</label>
    <div class="be-list__find-pop-row">
        <input class="be-input be-list__find-input" type="search" id="<?= e($field) ?>" name="<?= e($key) ?>" form="<?= e($form) ?>"
               value="<?= e($value) ?>" autocomplete="off" autofocus
               aria-invalid="<?= $invalid ? 'true' : 'false' ?>"<?= $inputMode !== null ? ' inputmode="' . e($inputMode) . '"' : '' ?>>
        <button type="submit" class="be-btn be-btn--primary" form="<?= e($form) ?>">Suchen</button>
    </div>
</div>
