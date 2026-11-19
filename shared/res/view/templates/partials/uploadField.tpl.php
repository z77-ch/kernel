<?php
/**
 * ONE FILE THAT TRAVELS WITH ITS FORM — the upload component's `field` look for a file the
 * enclosing form posts together with its other fields (owner 2026-11-19: the voucher of «Geschäftlich
 * buchen …» was a bare browser file field, looked like nothing else and pushed the window wider).
 * `partials/upload` cannot serve here: it IS a form and sends each file on its own request.
 *
 * No form of its own: the file field belongs to the form it stands in (or to `$form` by the `form`
 * attribute). The look is the component's (`z77-upload--field`: one row, arrow, «Datei ziehen
 * oder auswählen», the hint); `upload.js` (`[data-upload-inline]`) shows the chosen file with
 * «entfernen» and takes a file dropped onto the zone. Without the script the zone is the label of
 * a real file field and still opens the dialog; the server's check stays the policy's
 * ({@see \Z77\Shared\Upload\UploadPolicy::check()}).
 *
 * @var \Z77\Shared\Upload\UploadPolicy $policy  one file (`multiple: false`); its `field` names the input
 * @var string      $id       the input's id (a `<label for>` elsewhere may name it)
 * @var string|null $form     the id of the form the file belongs to, when it stands outside it
 * @var bool|null   $invalid  marks the field invalid (`aria-invalid`)
 */

use Z77\Shared\Upload\UploadPolicy;

/** @var UploadPolicy $policy */
$accept = $policy->accept === [] ? '' : implode(',', $policy->accept);
$hint   = $policy->hint !== '' ? $policy->hint : trim(implode(' · ', array_filter([
    $accept === '' ? '' : strtoupper(str_replace(['.', ','], ['', ', '], $accept)),
    'bis ' . UploadPolicy::formatBytes($policy->effectiveMaxBytes()),
])));
?>
<div class="z77-upload z77-upload--field" data-upload-inline data-upload-max="<?= e((string) $policy->effectiveMaxBytes()) ?>"<?= $accept === '' ? '' : ' data-upload-accept="' . e($accept) . '"' ?>>
    <input class="z77-upload__input" id="<?= e($id) ?>" type="file" name="<?= e($policy->field) ?>"<?= $accept === '' ? '' : ' accept="' . e($accept) . '"' ?><?= ($form ?? '') !== '' ? ' form="' . e($form) . '"' : '' ?> aria-invalid="<?= !empty($invalid) ? 'true' : 'false' ?>">
    <label class="z77-upload__zone" for="<?= e($id) ?>" data-upload-zone>
        <span class="z77-upload__arrow" aria-hidden="true">↑</span>
        <span class="z77-upload__label" data-upload-chosen-name><?= e($policy->label !== '' ? $policy->label : 'Datei ziehen oder') ?></span>
        <span class="z77-upload__pick" data-upload-chosen-pick><?= e(t('shared.upload.pick')) ?></span>
        <?php if ($hint !== ''): ?><span class="z77-upload__hint"><?= e($hint) ?></span><?php endif; ?>
    </label>
    <button type="button" class="be-btn be-btn--quiet be-btn--sm" data-upload-clear hidden>entfernen</button>
</div>
