<?php
/**
 * «? Hilfe» — the page's help trigger in the TOP BAR (ADR-048 addendum, owner 2026-10-08).
 * A shell renders it in its top bar's right cluster ONLY when the controller attached help
 * (`$helpBlock`); it replaced the «i» in the crumb line, so the crumb line is the same on
 * every page. Backend: `partials/shell/topbar`; member: the head, `partials/shell/userMenu`
 * (owner 2026-10-09).
 *
 * Rendered `hidden`: the help is read in a movable, non-modal window that only the script
 * can build — without JavaScript there is no help, so there is no button either. core.js
 * (`_Z77.core.help.bind()`) reveals it. A click (or F1 in a form field) opens the help at the
 * section of the field last focused (`data-help-field`), else at the top.
 *
 * «KI-Assistent» (ADR-055): with `$assistant` (the backend shell passes it when the assistant
 * is configured) the same button becomes the assistant's opener — label «KI-Assistent», the
 * screen's help topic as `data-kibot-context`. bot.js takes the click first (capture); its
 * discreet «Hilfetext anzeigen» hands the click back, and core.js opens the classic help as
 * before. Until bot.js is ready (or when it fails) the click still opens the help; F1 always does.
 *
 * The label hides on a phone (host CSS); the accessible name stays.
 *
 * @var array{topic: string, label: string}|null $assistant
 */
?>
<?php if (!empty($assistant)): ?>
<button type="button" class="z77-help-trigger z77-help-trigger--assistant" data-help-open data-help-trigger hidden data-kibot-open data-kibot-fallback data-kibot-context="<?= e($assistant['topic'] ?? '') ?>" data-kibot-context-label="<?= e($assistant['label'] ?? '') ?>" aria-label="KI-Assistent" title="KI-Assistent — Fragen zur Bedienung"><span class="z77-help-trigger__icon" aria-hidden="true">✦</span><span class="z77-help-trigger__label" aria-hidden="true">KI-Assistent</span></button>
<?php else: ?>
<button type="button" class="z77-help-trigger" data-help-open data-help-trigger hidden aria-label="Hilfe (F1)" title="Hilfe (F1)"><span class="z77-help-trigger__icon" aria-hidden="true">?</span><span class="z77-help-trigger__label" aria-hidden="true">Hilfe</span></button>
<?php endif; ?>
