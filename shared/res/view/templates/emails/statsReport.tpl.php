<?php
/**
 * Body of the monthly statistics mail (`stats-report-mail`, docs/topics/stats.md).
 * Rendered by the EmailService inside the shared emails/layout; referenced from
 * emailConfig as ['emails/statsReport', 'Z77\\Shared'].
 *
 * Short on purpose: the period, the headline figures and THE LINK — no table, no
 * attachment (Bauplan step 2: the link is the report). Every `<tr data-str="new-line">`
 * and closing block tag becomes a line break in the plain-text part (HtmlToText).
 *
 * @var string $siteName  host of canonicalBaseUrl, e.g. zihlundsee.ch
 * @var array  $report    StatsReport::build()
 * @var array  $headline  StatsReport::headline() — label/value pairs
 * @var string $url       the tokenized report link
 * @var int    $expiresAt unix time the link stops working
 */
?>
<p style="margin:0 0 4px;font-size:18px;font-weight:bold;">Besuchsstatistik <?= e($report['monthLabel']) ?></p>
<p style="margin:0 0 20px;color:#666666;"><?= e($siteName) ?></p>

<?php if (!empty($report['startsLate']) && $report['countedFrom'] !== null): ?>
<p style="margin:0 0 16px;">Gezählt ab <?= e(date('j.n.Y', strtotime($report['countedFrom']))) ?> — der Monat ist darum nicht vollständig.</p>
<?php endif; ?>

<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <?php foreach ($headline as $row): ?>
    <tr data-str="new-line">
        <td style="padding:2px 16px 2px 0;color:#666666;"><?= e($row['label']) ?></td>
        <td style="padding:2px 0;font-weight:bold;text-align:right;"><?= e($row['value']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<p style="margin:0 0 8px;">Der ganze Bericht — Seiten, Herkunft, Geräte und was die Besucher angeklickt haben:</p>
<p style="margin:0 0 24px;"><a href="<?= e($url) ?>" style="color:#1a5fb4;font-weight:bold;"><?= e($url) ?></a></p>

<p style="margin:0;color:#666666;font-size:12px;">Der Link ist bis <?= e(date('j.n.Y', $expiresAt)) ?> gültig und öffnet den Bericht ohne Anmeldung — wer ihn hat, sieht den Bericht.</p>
