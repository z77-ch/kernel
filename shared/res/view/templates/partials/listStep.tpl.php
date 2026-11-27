<?php
/**
 * The open STEP on top of a standard list (listing.md, owner 2026-11-27): when work this list
 * produced waits somewhere else for its next step, a bordered row at the TOP of the list's card
 * says so and leads there — the status sign, the bold title, one line under it, «→» at the right;
 * the whole box is the link. First user: the order list's open Faktura-Journal («1 Rechnung nicht
 * verbucht»).
 *
 * The controller hands the words, the target and the weight (the weight from the module that owns
 * the work — the same decision as its dashboard entry); this partial decides nothing beyond the
 * class of the weight. The sign is CSS (`.be-list__step`, `--urgent`, module-backend's
 * `_list.scss`, STATUS-SIGNS-001): the clock for todo, the danger disc for urgent — the text must
 * say WHY it is urgent, the colour alone does not. A plain link: it leaves the list (no region
 * link). A kernel partial, namespace `Z77\Shared`.
 *
 * @var string $title                                    the place of the step («Faktura-Journal»)
 * @var string $text                                     what waits there («1 Rechnung nicht verbucht»)
 * @var string $href                                     where the step is done
 * @var \Z77\Shared\Dashboard\Severity|null $severity    Urgent → danger sign; Todo / null → clock
 */
use Z77\Shared\Dashboard\Severity;

$urgent = ($severity ?? null) === Severity::Urgent;
?>
<a class="be-list__step<?= $urgent ? ' be-list__step--urgent' : '' ?>" href="<?= e($href) ?>" data-list-step>
    <span class="be-list__step-body">
        <strong class="be-list__step-title"><?= e($title) ?></strong>
        <span class="be-list__step-text"><?= e($text) ?></span>
    </span>
    <span class="be-list__step-go" aria-hidden="true">→</span>
</a>
