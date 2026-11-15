<?php
/**
 * The installation's addition to a help (owner 2026-11-15, «Hilfe ergänzen»;
 * {@see \Z77\Core\Services\HelpAddition}): below the help text, under a small heading. Rendered by
 * `HelpService::additionHtml()` into the help block. After a save core.js builds the SAME shape from
 * the texts of the `help-addition` command (`_additionSection()`) — keep the two alike.
 *
 * Plain text from an admin: every paragraph escaped with e(), a line break inside a paragraph
 * becomes <br> AFTER escaping. No HTML from the user, ever.
 *
 * @var list<string> $paragraphs  non-empty
 */
?>
<section class="z77-help__addition" data-help-addition>
    <h4 class="z77-help__addition-title"><?= e(t('common.helpAddition')) ?></h4>
    <?php foreach ($paragraphs as $paragraph): ?>
    <p><?= nl2br(e($paragraph), false) ?></p>
    <?php endforeach; ?>
</section>
