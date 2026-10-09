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
 *   cell   a button for the shell's action cell; the WHOLE work area is the drop target
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

$inputId = 'z77-upload-' . substr(hash('sha256', $policy->endpoint . $shape), 0, 8);
?>
<form class="z77-upload z77-upload--<?= e($shape) ?>" method="post" enctype="multipart/form-data"<?= $attributes ?>>
    <?= $csrfField ?? '' ?>
    <?= $fields ?? '' ?>
    <input class="z77-upload__input" id="<?= e($inputId) ?>" type="file" name="<?= e($policy->field) ?>"
           <?= $policy->multiple ? 'multiple ' : '' ?><?= $accept === '' ? '' : 'accept="' . e($accept) . '" ' ?>required>

    <?php if ($shape === 'cell'): ?>
    <?php // The action cell carries ONE button; the drop target is the work area, which the
          // script marks while files are over the window. ?>
    <label class="be-btn be-btn--primary z77-upload__cell" for="<?= e($inputId) ?>">
        <span class="z77-upload__arrow" aria-hidden="true">↑</span>
        <span class="z77-upload__cell-label"><?= e($label) ?></span>
    </label>
    <?php else: ?>
    <label class="z77-upload__zone" for="<?= e($inputId) ?>" data-upload-zone>
        <span class="z77-upload__arrow" aria-hidden="true">↑</span>
        <span class="z77-upload__label"><?= e($label) ?></span>
        <span class="z77-upload__pick">auswählen</span>
        <?php if ($hint !== ''): ?>
        <span class="z77-upload__hint"><?= e($hint) ?></span>
        <?php endif; ?>
    </label>
    <?php endif; ?>

    <?php // The queue: written by the script, empty (and hidden) until files are chosen. A
          // row carries name, progress and outcome; the summary carries the overall bar. ?>
    <ul class="z77-upload__list" data-upload-list hidden></ul>
    <p class="z77-upload__summary" data-upload-summary hidden>
        <span class="z77-upload__bar" aria-hidden="true"><span class="z77-upload__bar-fill" data-upload-bar></span></span>
        <span class="z77-upload__count" data-upload-count></span>
    </p>

    <?php // Without the script this is the only way to send; with it, it is removed on bind. ?>
    <button class="be-btn be-btn--primary z77-upload__submit" type="submit" data-upload-submit>Hochladen</button>
</form>
