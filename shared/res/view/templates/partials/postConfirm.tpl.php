<?php
/**
 * «Journal verbuchen» as a WINDOW (owner decision E16, 2026-11-12; ADR-047) — the shared last
 * look before a journal is posted, built once for every journal (Rule 9; first users: the
 * Belegjournal of module-receipt, the Faktura-Journal of module-debtor). The CONTROLLER judges
 * the run and hands in strings only; this partial decides nothing but which of three shapes it
 * draws:
 *
 *   - ERRORS (`$errors` not empty): ONLY the errors — per record what is wrong and, where the
 *     record has one, a link that opens its window (`data-window-open`, a child of this window).
 *     No summary, no post button.
 *   - EMPTY (`$empty` not ''): the sentence, no post button.
 *   - SUMMARY: a short summary — the facts (count, total), the VAT per code, muted notes (what
 *     stays behind) — and the green «Jetzt buchen» (`modalActions` kind `confirm`): a POST to
 *     `$action` (`#[Csrf]` at the controller) carrying `$hidden` (the run as shown: `{id}:{version}`
 *     pairs, a cut-off) — the controller refuses when the run changed since.
 *
 * An optional FILTER (`$filter`, e.g. the cut-off date) is a GET form `data-window-form="change"`
 * (core.js «windows»): inside the window a change re-renders the window in place; without the
 * script it is a plain GET and the URL renders the same content as a page. The action row stands
 * first (ADR-049); in the window «Schliessen» closes it, as a page «Zum Journal» goes back.
 *
 * @var bool   $window      fetched as a window (else the page — the no-JS fallback)
 * @var string $origin      WindowOrigin of the opening request (echoed as `_origin`)
 * @var string $mask        the window's mask (`data-window`)
 * @var string $entity      the record it shows (`<type>:<id>`, `data-window-entity`)
 * @var string $title       the window title / the page heading
 * @var string $width       the window width (default 40rem)
 * @var string $action      URL of the POST
 * @var string $csrfToken
 * @var array<string, string|list<string>> $hidden  name → value; a list → `name[]` per value
 * @var array{action: string, name: string, label: string, value: string, type?: string, id?: string, helpKey?: string, note?: string, hint?: string}|null $filter
 * @var list<array{label: string, reason: string, url?: string}> $errors
 * @var string $errorsTitle the sentence above the errors
 * @var string $empty       nothing to post — the sentence
 * @var list<array{0: string, 1: string}> $facts  label, value
 * @var list<array{code: string, label: string, rate: string, base: string, tax: string}> $vat
 * @var list<string> $notes  muted lines under the summary (e.g. later receipts that stay)
 * @var string $hint         the rule under the summary (all or nothing)
 * @var string $submit       the post button (default «Jetzt buchen»)
 * @var string $back         the page's way back («Zum Journal»)
 */
$window      = !empty($window);
$title       = (string) ($title ?? 'Journal verbuchen');
$width       = (string) ($width ?? '40rem');
$hidden      = (array) ($hidden ?? []);
$filter      = $filter ?? null;
$errors      = (array) ($errors ?? []);
$empty       = (string) ($empty ?? '');
$facts       = (array) ($facts ?? []);
$vat         = (array) ($vat ?? []);
$notes       = (array) ($notes ?? []);
$hint        = (string) ($hint ?? '');
$shape       = $errors !== [] ? 'errors' : ($empty !== '' ? 'empty' : 'summary');
$winAttr     = $window ? ' data-window="' . e((string) $mask) . '" data-window-entity="' . e((string) $entity) . '" data-window-title="' . e($title) . '" data-window-width="' . e($width) . '"' : '';
$filterId    = (string) ($filter['id'] ?? 'post-confirm-' . ($filter['name'] ?? 'filter'));
?>
<div class="be-list" data-post-confirm="<?= $shape ?>"<?= $winAttr ?>>
    <form method="post" action="<?= e((string) $action) ?>" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php if ($shape === 'summary'): ?>
        <?php foreach ($hidden as $name => $value): ?>
        <?php foreach (is_array($value) ? $value : [$value] as $one): ?>
        <input type="hidden" name="<?= e($name . (is_array($value) ? '[]' : '')) ?>" value="<?= e((string) $one) ?>">
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $shape === 'summary' ? (string) ($submit ?? 'Jetzt buchen') : '',
            'kind'       => 'confirm',
            'cancel'     => $window ? 'Schliessen' : 'Zum Journal',
            'cancelHref' => $window ? '' : (string) ($back ?? ''),
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
    </form>

    <?php if ($filter !== null): ?>
    <form method="get" action="<?= e((string) $filter['action']) ?>" class="be-list__section" data-window-form="change">
        <div class="be-form__grid be-form__grid--fill">
            <div class="be-form__field">
                <label for="<?= e($filterId) ?>"><?= e((string) $filter['label']) ?></label>
                <input type="<?= e((string) ($filter['type'] ?? 'date')) ?>" id="<?= e($filterId) ?>" name="<?= e((string) $filter['name']) ?>" value="<?= e((string) $filter['value']) ?>" required data-help-key="<?= e((string) ($filter['helpKey'] ?? $filter['name'])) ?>">
            </div>
            <div class="be-form__field">
                <button type="submit" class="be-btn be-btn--ghost">Anzeigen</button>
            </div>
        </div>
        <?php if (($filter['note'] ?? '') !== ''): ?><p class="be-form__hint be-form__hint--warn"><?= e((string) $filter['note']) ?></p><?php endif; ?>
        <?php if (($filter['hint'] ?? '') !== ''): ?><p class="be-form__hint"><?= e((string) $filter['hint']) ?></p><?php endif; ?>
    </form>
    <?php endif; ?>

    <div class="be-list__section">
        <?php if ($shape === 'errors'): ?>
        <div class="be-modal__alert be-modal__alert--error" role="status" data-post-confirm-errors="<?= count($errors) ?>">
            <strong><?= e((string) ($errorsTitle ?? 'Verbuchen gesperrt:')) ?></strong>
            <ul class="be-modal__alert-list">
                <?php foreach ($errors as $error): $url = (string) ($error['url'] ?? ''); $label = (string) ($error['label'] ?? ''); ?>
                <li><?php if ($url !== '' && $label !== ''): ?><a href="<?= e($url) ?>" data-window-open="<?= e($url) ?>"><?= e($label) ?></a> — <?php elseif ($label !== ''): ?><?= e($label) ?> — <?php endif; ?><?= e((string) $error['reason']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php elseif ($shape === 'empty'): ?>
        <div class="be-modal__alert" role="status"><?= e($empty) ?></div>
        <?php else: ?>
        <div class="be-list__table" style="--be-list-cols: 10rem minmax(10rem, 1fr)" data-post-confirm-facts>
            <?php foreach ($facts as [$label, $value]): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--muted"><?= e((string) $label) ?></span>
                <strong class="be-list__cell be-list__cell--mono"><?= e((string) $value) ?></strong>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php if ($vat !== []): ?>
        <h3 class="be-list__section-title">MWST je Code</h3>
        <div class="be-list__table" style="--be-list-cols: 5rem minmax(8rem, 2fr) 5rem 8rem 8rem" data-post-confirm-vat>
            <div class="be-list__head">
                <span class="be-list__col">Code</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Satz</span>
                <span class="be-list__col be-list__col--num">Basis</span>
                <span class="be-list__col be-list__col--num">MWST</span>
            </div>
            <?php foreach ($vat as $row): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e((string) $row['code']) ?></span>
                <span class="be-list__cell"><?= e((string) $row['label']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e((string) $row['rate']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e((string) $row['base']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e((string) $row['tax']) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php foreach ($notes as $note): ?>
        <p class="be-form__hint be-list__cell--muted" data-post-confirm-note><?= e((string) $note) ?></p>
        <?php endforeach; ?>
        <?php if ($hint !== ''): ?><p class="be-form__hint"><?= e($hint) ?></p><?php endif; ?>
        <?php endif; ?>
    </div>
</div>
