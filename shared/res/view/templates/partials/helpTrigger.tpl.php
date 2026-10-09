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
 * The label hides on a phone (host CSS); the accessible name stays.
 */
?>
<button type="button" class="z77-help-trigger" data-help-open data-help-trigger hidden aria-label="Hilfe (F1)" title="Hilfe (F1)"><span class="z77-help-trigger__icon" aria-hidden="true">?</span><span class="z77-help-trigger__label" aria-hidden="true">Hilfe</span></button>
