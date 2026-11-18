<?php
/**
 * THE upload — one component for every file that enters the installation (owner
 * 2026-10-08/09, canvas board «Upload — Entwurf»). The controller hands in a
 * {@see \Z77\Shared\Upload\UploadPolicy}; this partial writes the form, `upload.js` gives it
 * drag & drop, per-file progress and per-file results.
 *
 * **It is a real `<form>`, and that is the fallback.** Without JavaScript the file field and
 * «Hochladen» stay, the browser posts them normally, and the endpoint answers with a redirect
 * — no drag & drop, no progress, but it works. The script only takes the submit away
 * (`data-upload` is its hook) and never builds the controls it needs.
 *
 * Three shapes, one behaviour (`$policy->shape`):
 *   drop   the drop zone in the work area — the file field is the zone's own label
 *   cell   a button for the shell's action cell; the WHOLE work area is the drop target, and
 *          the run's rows show in a window (`upload.js`, UPLOAD-DIALOG-001), never in the page.
 *          With `$policy->before` the button opens that window instead of the file dialog
 *          (what to prepare before picking); the window's own label picks the files
 *   field  one file inside a form: the zone is small, a chosen file shows with «entfernen»
 *
 * The CSRF token travels as the global hidden field the backend writes into every form; the
 * script reads it from `meta[name=csrf-token]` for its own requests (fetch contract,
 * `docs/topics/fetch.md`).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $policy
 * @var string|null $csrfField  rendered hidden CSRF input (host-provided), optional
 * @var string|null $fields     extra form fields of the CONSUMER, rendered inside the form
 *                              above the zone (the Drive's target folder and its «Original
 *                              ausliefern» switch). They travel with every file of the run,
 *                              because `upload.js` collects the form's own fields per
 *                              request — so a select belongs in HERE and not beside the
 *                              component, where it would reach nothing.
 * @var string|null $extra      name of a per-file field provider registered in JS
 *                              (`_Z77.upload.provider('<name>', fn)`) — for what only a
 *                              module can produce, e.g. the Drive's video poster frame
 * @var string|null $transport  name of a transport registered in JS
 *                              (`_Z77.upload.transport('<name>', {...})`) that may carry a
 *                              file over another wire than one multipart POST — the DMS
 *                              chunked upload for files bigger than one request (F3)
 * @var array|null  $transportConfig what that transport needs (endpoints, sizes), written as
 *                              JSON into `data-upload-transport-config` — the server is the
 *                              source, the script carries no default of its own
 */

use Z77\Shared\Upload\UploadPolicy;

/** @var UploadPolicy $policy */
$shape    = $policy->shape;
$maxLabel = UploadPolicy::formatBytes($policy->effectiveMaxBytes());
$accept   = $policy->accept === [] ? '' : implode(',', $policy->accept);

$label = $policy->label !== ''
    ? $policy->label
    : match ($shape) {
        'cell'  => 'Dateien hochladen',
        'field' => $policy->multiple ? 'Dateien ziehen oder' : 'Datei ziehen oder',
        default => $policy->multiple ? 'Dateien hierher ziehen' : 'Datei hierher ziehen',
    };

// The hint states the three things a user cannot guess and would otherwise learn from an
// error: what is allowed, how big, how many.
$hint = $policy->hint !== '' ? $policy->hint : trim(implode(' · ', array_filter([
    $accept === '' ? '' : strtoupper(str_replace(['.', ','], ['', ', '], $accept)),
    'bis ' . $maxLabel,
    $policy->multiple ? 'mehrere' : 'eine Datei',
])));

$attributes = '';
foreach ($policy->attributes() as $name => $value) {
    $attributes .= ' ' . $name . '="' . e($value) . '"';
}
if (($extra ?? '') !== '') {
    $attributes .= ' data-upload-extra="' . e($extra) . '"';
}
if (($transport ?? '') !== '') {
    $attributes .= ' data-upload-transport="' . e($transport) . '"'
        . ' data-upload-transport-config="' . e((string) json_encode($transportConfig ?? [])) . '"';
}

$inputId = $policy->inputId();
// `before`: the cell's button opens a window first (core's `[data-window-open]` takes the click, so
// the label does not reach the file field); the window carries the pick. Without the script the
// label opens the file dialog as ever.
$before  = $shape === 'cell' && $policy->before !== '' ? ' data-window-open="' . e($policy->before) . '" data-upload-before' : '';
?>
<form class="z77-upload z77-upload--<?= e($shape) ?>" method="post" enctype="multipart/form-data"<?= $attributes ?>>
    <?= $csrfField ?? '' ?>
    <?= $fields ?? '' ?>
    <input class="z77-upload__input" id="<?= e($inputId) ?>" type="file" name="<?= e($policy->field) ?>"
           <?= $policy->multiple ? 'multiple ' : '' ?><?= $accept === '' ? '' : 'accept="' . e($accept) . '" ' ?>required>

    <?php if ($shape === 'cell'): ?>
    <?php // The action cell carries ONE button; the drop target is the work area, which the
          // script marks while files are over the window. ?>
    <?php // `be-btn__label` is not decoration: the shell hides exactly that class on a
          // phone (visually, so the button keeps its name) and the cell becomes a square
          // with the arrow alone — owner 2026-10-10, «in mobile muss der Upload auch in
          // der Werkzeugzeile erscheinen». ?>
    <?php // While a run lasts the label gives way to its count («3 / 20», `data-upload-running`),
          // and a click shows the run's window again instead of the file dialog — the rows run
          // in a window, not in the page (UPLOAD-DIALOG-001, owner 2026-11-17). ?>
    <label class="be-btn be-btn--primary z77-upload__cell" for="<?= e($inputId) ?>"<?= $before ?>>
        <span class="z77-upload__arrow" aria-hidden="true">↑</span>
        <span class="be-btn__label z77-upload__cell-label" data-upload-label><?= e($label) ?></span>
        <span class="z77-upload__cell-count" data-upload-running></span>
    </label>
    <?php else: ?>
    <label class="z77-upload__zone" for="<?= e($inputId) ?>" data-upload-zone>
        <span class="z77-upload__arrow" aria-hidden="true">↑</span>
        <span class="z77-upload__label"><?= e($label) ?></span>
        <span class="z77-upload__pick"><?= e(t('shared.upload.pick')) ?></span>
        <?php if ($hint !== ''): ?>
        <span class="z77-upload__hint"><?= e($hint) ?></span>
        <?php endif; ?>
    </label>
    <?php endif; ?>

    <?php // The queue: written by the script, empty (and hidden) until files are chosen. A
          // row carries name, progress and outcome; the summary carries the overall bar. The
          // `cell` shape moves both into its window on bind. ?>
    <ul class="z77-upload__list" data-upload-list hidden></ul>
    <p class="z77-upload__summary" data-upload-summary hidden>
        <span class="z77-upload__bar" aria-hidden="true"><span class="z77-upload__bar-fill" data-upload-bar></span></span>
        <span class="z77-upload__count" data-upload-count></span>
    </p>

    <?php // Without the script this is the only way to send; with it, it is removed on bind. ?>
    <button class="be-btn be-btn--primary z77-upload__submit" type="submit" data-upload-submit><?= e(t('shared.upload.submit')) ?></button>
</form>
