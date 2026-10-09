<?php
/**
 * The «i» that opens the help window (ADR-048) — rendered by the MEMBER skeleton in its crumb
 * line when the controller attached help (`$helpBlock`); core.js adds the same button to a
 * window's title bar. The backend opens page help from the top bar instead (`partials/helpTrigger`,
 * ADR-048 addendum 2026-10-08); the member move is pending (`fetch.md`). Needs the script (the help window is a movable, non-modal window — CSS cannot move it).
 */
?>
<button type="button" class="z77-help-open" data-help-open aria-label="Hilfe" title="Hilfe">i</button>
