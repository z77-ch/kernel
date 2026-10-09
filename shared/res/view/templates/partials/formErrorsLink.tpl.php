<?php
/**
 * «2 Fehler» in a form's action bar (ADR-049): a save from the top with an error further down
 * must not look like nothing happened. A `<label for>` of the first invalid field — a click
 * focuses it and the browser scrolls it into view. No script. Renders nothing without errors.
 *
 * @var int    $count   the invalid fields
 * @var string $target  the id of the first invalid field in document order
 */
if (($count ?? 0) < 1 || ($target ?? '') === '') {
    return;
}
?>
<label class="z77-form-actions__errors" for="<?= e($target) ?>"><?= (int) $count ?> Fehler</label>
