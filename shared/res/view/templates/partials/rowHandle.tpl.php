<?php
/**
 * The HANDLE cell of a document row (core.js «rows», css-backend.md «rows, autosize, line sums»):
 * the ⋮⋮ grip and the ⇥ indent toggle — the first cell of every positions table that the user
 * arranges (the order window, debtor's invoice editor; Rule 9 — one look per control kind).
 *
 * The grip is a `type="button"` rendered `hidden`: core.js reveals it, so a page without the
 * script shows no dead control (the rows keep the rendered order). The indent is a CHECKBOX that
 * posts `{name}[child]=1` — it works without the script; CSS shows it as ⇥ (`:has(:checked)`).
 *
 *   <?= $this->partial('partials/rowHandle', ['name' => 'rows[3]', 'id' => 'order-row-3-', 'child' => true], 'Z77\\Shared') ?>
 *
 * @var string $name     the row's field prefix (`rows[3]`; `rows[__i__]` in a row template)
 * @var string $id       the row's id prefix (`order-row-3-`) — the checkbox is `{id}child`
 * @var bool   $child    the row is indented beneath the row above
 * @var bool   $movable  render the grip (default true; false = a read-only document)
 * @var bool   $invalid  the indent carries an error (`aria-invalid`)
 */
$movable = $movable ?? true;
?>
<span class="be-list__cell be-rows__handle">
    <?php if ($movable): ?>
    <button type="button" class="be-rows__grip" data-row-grip hidden title="Verschieben: ziehen oder Alt+↑ / Alt+↓" aria-label="Zeile verschieben (ziehen oder Alt+Pfeil auf / ab)">⋮⋮</button>
    <?php endif; ?>
    <label class="be-rows__indent" title="Einrücken: unter die Position darüber">
        <input type="checkbox" class="be-rows__indent-input" id="<?= e($id) ?>child" name="<?= e($name) ?>[child]" value="1"<?= !empty($child) ? ' checked' : '' ?> data-row-indent data-help-key="child" aria-label="Unter die Position darüber einrücken" aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
        <span aria-hidden="true">⇥</span>
    </label>
</span>
