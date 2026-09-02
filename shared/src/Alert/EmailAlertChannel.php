<?php

namespace Z77\Shared\Alert;

use Z77\Shared\Mail\EmailMessage,
    Z77\Shared\Mail\EmailService
;

/**
 * The framework's default alert channel: a plain-text operator mail via the
 * HTTP-free {@see EmailService} (ADR-030 — sendable from cron, links never
 * derived from a request). Wording is deliberately terse English; a project
 * wanting different wording ships its own channel implementation.
 */
final class EmailAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private readonly string $to,
        private readonly ?EmailService $emailService = null,
    ) {
    }

    public function send(AlertMessage $message): void
    {
        $mail = (new EmailMessage())
            ->to($this->to)
            ->subject($this->subject($message))
            ->text($this->body($message));

        $service = $this->emailService ?? new EmailService();
        if (!$service->send($mail)) {
            throw new \RuntimeException(
                'alert mail not sent: ' . implode('; ', $service->getLastErrors())
            );
        }
    }

    private function subject(AlertMessage $m): string
    {
        return match ($m->kind) {
            AlertKind::Outage     => "[ALERT] {$m->source} failing",
            AlertKind::Escalation => "[ALERT] {$m->source} STILL failing (" . $this->age($m->failingFor()) . ')',
            AlertKind::Recovery   => "[OK] {$m->source} recovered",
        };
    }

    private function body(AlertMessage $m): string
    {
        $lines = [
            'source:       ' . $m->source,
            'event:        ' . $m->kind->value,
        ];
        if ($m->code !== '') {
            $lines[] = 'code:         ' . $m->code;
        }
        if ($m->failingSince !== null) {
            $lines[] = 'failing since: ' . date('c', $m->failingSince)
                . ' (' . $this->age($m->failingFor()) . ')';
        }
        $lines[] = 'last success: ' . ($m->lastSuccess !== null
            ? date('c', $m->lastSuccess) . ' — served stand is ' . $this->age($m->staleFor()) . ' old'
            : 'unknown');
        foreach ($m->context as $key => $value) {
            $lines[] = str_pad($key . ':', 14) . $value;
        }

        return implode("\n", $lines) . "\n";
    }

    private function age(?int $seconds): string
    {
        if ($seconds === null) {
            return 'unknown';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'min';
        }
        if ($seconds < 172800) {
            return round($seconds / 3600, 1) . 'h';
        }
        return round($seconds / 86400, 1) . 'd';
    }
}
