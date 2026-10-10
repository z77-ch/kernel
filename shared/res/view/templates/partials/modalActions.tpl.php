<?php
/**
 * The action row of a dialog or window form (ADR-049, revision 2026-10-10): ONE fixed row,
 * directly under the header — never at the bottom, never in the middle. Renders
 * `.z77-form-actions` with the primary submit FIRST in document order (Enter presses it,
 * ADR-049 §5), then the cancel, then whatever the caller adds, then the «n Fehler» link.
 *
 * Place it right after `.be-modal__header`, before `.be-modal__body`. The host CSS pins it
 * there (the popup content is a flex column, the row has `order: -1`) and gives it the
 * header's padding. With `end` the host CSS moves the row to the BOTTOM (`order: 1`), so its
 * place in the template does not matter — write it after the header anyway, one shape.
 *
 *   <?= $this->partial('partials/modalActions', ['submit' => 'Speichern'], 'Z77\\Shared') ?>
 *
 * @var string      $submit       label of the primary submit button; '' renders none
 * @var string      $kind         'primary' (default) | 'danger' — the submit's look
 * @var array       $submitAttrs  further attributes of the submit (e.g. ['name' => 'op', 'value' => 'save', 'form' => 'id'])
 * @var string      $cancel       label of the cancel button ('Abbrechen' default; '' renders none).
 *                                Closes the popup (`data-popup-close`); inside an ADR-047 window
 *                                hand in $cancelHref instead — a link the window follows.
 * @var string      $cancelHref   optional: cancel as a link (window read view); gets data-window-link
 * @var string      $extra        raw HTML of further controls (already escaped by the caller)
 * @var array       $errors       ['count' => int, 'target' => first invalid field id] → partials/formErrorsLink
 * @var bool        $end          true → `--end`: the bar at the bottom. ADR-049 allows this for
 *                                exactly one shape — a confirm without any input field.
 * @var string      $class        further classes on the row
 */
$submit      = (string) ($submit ?? 'Speichern');
$kind        = ($kind ?? 'primary') === 'danger' ? 'danger' : 'primary';
$submitAttrs = (array) ($submitAttrs ?? []);
$cancel      = (string) ($cancel ?? 'Abbrechen');
$cancelHref  = (string) ($cancelHref ?? '');
$extra       = (string) ($extra ?? '');
$errors      = (array) ($errors ?? []);
$end         = (bool) ($end ?? false);
$class       = trim('z77-form-actions' . ($end ? ' z77-form-actions--end' : '') . ' ' . (string) ($class ?? ''));

$attrs = '';
foreach ($submitAttrs as $name => $value) {
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', (string) $name)) {
        continue;
    }
    $attrs .= ' ' . $name . '="' . e((string) $value) . '"';
}
?>
<div class="<?= e($class) ?>">
    <?php if ($submit !== ''): ?>
    <button type="submit" class="be-btn be-btn--<?= $kind ?>"<?= $attrs ?>><?= e($submit) ?></button>
    <?php endif; ?>
    <?= $extra ?>
    <?php if ($cancel !== '' && $cancelHref !== ''): ?>
    <a class="be-btn be-btn--ghost" href="<?= e($cancelHref) ?>" data-window-link><?= e($cancel) ?></a>
    <?php elseif ($cancel !== ''): ?>
    <button type="button" class="be-btn be-btn--ghost" data-popup-close><?= e($cancel) ?></button>
    <?php endif; ?>
    <?php if (($errors['count'] ?? 0) > 0): ?>
    <?= $this->partial('partials/formErrorsLink', ['count' => (int) $errors['count'], 'target' => (string) ($errors['target'] ?? '')], 'Z77\\Shared') ?>
    <?php endif; ?>
</div>
