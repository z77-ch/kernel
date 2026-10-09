<?php

namespace Z77\Shared\Stats;

use Z77\Core\DI;

/**
 * Sends the statistics report mail for ONE month — the single send path of
 * docs/topics/stats.md «report». Two callers, both explicit about the month:
 *
 *   StatsReportMailJob             the monthly schedule (`monthly@1,06:00`):
 *                                  the month before the run
 *   StatsController::sendAction    «jetzt senden» on the backend page: the
 *                                  month on screen, sent IN the request, the
 *                                  result shown at once
 *
 * The backend button used to queue the job instead (2026-09-26). That made a
 * hand send depend on the cron, put its result on another screen, and let
 * «Jetzt einreihen» on the job screen — which carries no month — look like
 * the same thing while it reported on the previous month. One mail is
 * well under a second; it belongs in the request that asked for it
 * (owner decision 2026-09-27).
 *
 * Returns what happened, it does not log or flash — the caller says it where
 * its user looks (job log, flash). `status`:
 *   sent     — the mail went out
 *   nothing  — the month has no aggregate; no mail, not an error
 *   failed   — no key, no canonicalBaseUrl, or the send was refused; `note` says which
 *
 * Recipients, cc and subject: the form key {@see FORM_KEY} through
 * `EmailService::sendForm()` — the project's `emailConfig` is the seed, a
 * record in Backend → Service → E-Mail overrides it (mail.md).
 */
final class StatsReportMailer
{
    public const FORM_KEY = 'statsReport';

    /**
     * @return array{status: string, note: string, expiresAt: ?int}
     */
    public function send(string $month): array
    {
        if (preg_match('~^\d{4}-(0[1-9]|1[0-2])$~', $month) !== 1) {
            return self::result('failed', "'{$month}' is not a report month (YYYY-MM)");
        }

        $tokens = ReportToken::fromConfig();
        if ($tokens === null) {
            return self::result('failed', 'no report key in config/client/' . ReportToken::CONFIG_FILE . ' — link and mail are off, nothing sent');
        }

        $base = defined('CANONICAL_BASE_URL') ? (string) CANONICAL_BASE_URL : '';
        if ($base === '') {
            return self::result('failed', 'no canonicalBaseUrl in config/client/systemConfig.inc.php — a link would point nowhere, nothing sent');
        }

        $reports = StatsReport::fromProject();
        if (!in_array($month, $reports->months(), true)) {
            return self::result('nothing', "no aggregate for {$month} — nothing to report, no mail sent");
        }
        $report = $reports->build($month, StatsEvents::declared());

        $expiresAt = ReportToken::expiresAt();
        $url       = $base . ReportToken::LINK_PATH . '/' . $tokens->mint($month, $expiresAt);

        $mail = DI::getEmailService();
        $sent = $mail->sendForm(self::FORM_KEY, [
            'siteName'  => (string) (parse_url($base, PHP_URL_HOST) ?: $base),
            'report'    => $report,
            'headline'  => StatsReport::headline($report),
            'url'       => $url,
            'expiresAt' => $expiresAt,
        ]);

        if (!$sent) {
            return self::result('failed', "report {$month} not sent: " . implode('; ', $mail->getLastErrors()));
        }

        return self::result(
            'sent',
            sprintf('report %s sent (form key %s), link valid until %s', $month, self::FORM_KEY, date('d.m.Y', $expiresAt)),
            $expiresAt
        );
    }

    private static function result(string $status, string $note, ?int $expiresAt = null): array
    {
        return ['status' => $status, 'note' => $note, 'expiresAt' => $expiresAt];
    }
}
