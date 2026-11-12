<?php
/**
 * The TEXT cell of a document row (core.js «rows» / «autosize», css-backend.md «rows, autosize,
 * line sums»): the line CODE as a small «eyebrow» field above the text, the text as a textarea as
 * tall as its text — the order window's and debtor's invoice editor's positions (Rule 9).
 *
 * The eyebrow (`.be-rows__eyebrow`, owner 2026-11-12) holds the line code — typically the service
 * date —, posted as `{name}[code]`, help key `code`, empty unless typed. The textarea sits in its
 * own box (`.be-rows__body`) so that autosize's mirror covers the text alone. `title` = the first
 * line is shown as the title (`data-autosize="title"`); without the script the server's `rows`
 * fit the text. Plain fields — the form posts them as typed (Rule 7).
 *
 *   <?= $this->partial('partials/rowText', ['name' => 'rows[3]', 'id' => 'order-row-3-', 'text' => $row['text'], 'code' => $row['code'], 'codeLength' => 16], 'Z77\\Shared') ?>
 *
 * @var string $name         the row's field prefix (`rows[3]`; `rows[__i__]` in a row template)
 * @var string $id           the row's id prefix — the fields are `{id}code` and `{id}text`
 * @var string $text         the text as typed / stored
 * @var string $code         the line code ('' = none)
 * @var int    $codeLength   the code's `maxlength` (the line entity's CODE_LENGTH)
 * @var bool   $title        the first line as the title (default true; false = a text row)
 * @var bool   $wide         the cell spans `--be-rows-span` tracks (a text row without number cells)
 * @var bool   $textInvalid  the text carries an error
 * @var bool   $codeInvalid  the code carries an error
 * @var array<string, string|true> $textAttrs  further attributes of the textarea (`data-fill-key` => 'text')
 */
$text  = (string) ($text ?? '');
$extra = '';
foreach ($textAttrs ?? [] as $attr => $value) {
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9:_-]*$/', (string) $attr)) {
        $extra .= ' ' . $attr . ($value === true ? '' : '="' . e((string) $value) . '"');
    }
}
?>
<span class="be-list__cell be-rows__text<?= !empty($wide) ? ' be-rows__wide' : '' ?>"><input class="be-input be-input--sm be-rows__eyebrow" type="text" id="<?= e($id) ?>code" name="<?= e($name) ?>[code]" value="<?= e((string) ($code ?? '')) ?>" maxlength="<?= (int) $codeLength ?>" spellcheck="false" placeholder="Code" data-help-key="code" aria-label="Code" aria-invalid="<?= !empty($codeInvalid) ? 'true' : 'false' ?>"><span class="be-rows__body"><textarea class="be-input be-input--sm" id="<?= e($id) ?>text" name="<?= e($name) ?>[text]" rows="<?= max(1, substr_count($text, "\n") + 1) ?>" data-autosize<?= ($title ?? true) ? '="title"' : '' ?> data-help-key="text" aria-label="Text" aria-invalid="<?= !empty($textInvalid) ? 'true' : 'false' ?>"<?= raw($extra) ?>><?= e($text) ?></textarea></span></span>
