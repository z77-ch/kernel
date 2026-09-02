<?php

namespace Z77\Shared\Alert;

/**
 * Edge-triggered outage alerting (alarm handoff, 2026-09-02): the caller
 * reports every probe outcome — `failure()` / `success()` per source — and
 * the service turns the stream into at most three messages per incident:
 *
 *   ok → failing                    Outage      (one alert)
 *   failing, longer than the window Escalation  (one alert — time-based, not attempt-based)
 *   failing → ok                    Recovery    (one alert)
 *
 * Everything else is deliberate silence — an outage lasts hours while the
 * revalidation runs every few minutes; one mail per attempt is not a report,
 * it is a filtering problem.
 *
 * State lives under `var/lib/alert/` — the SHARED branch of `var/` (same
 * reasoning as the throttle counters, ADR-034/ADR-035): a release switch
 * must neither re-announce yesterday's outage nor forget a running one.
 * One JSON file per source, single-writer by nature (probes are TTL-paced);
 * writes are LOCK_EX all the same.
 *
 * The alert path never breaks the caller: a channel that throws is logged to
 * error_log and the remaining channels still fire. No HTTP-bound work here —
 * this runs from cron (ADR-030 shape).
 *
 * Who calls this: the FRAMEWORK-side integration (a site's revalidation
 * cron/controller), never a framework-agnostic library — e.g. the propbase
 * client keeps returning its result, and the caller reports it here.
 */
final class AlertService
{
    /** @param list<AlertChannelInterface> $channels */
    public function __construct(
        private readonly string $stateDir,
        private readonly array $channels,
        private readonly int $escalationSeconds = 4 * 3600,
    ) {
    }

    /** The conventional state directory of an installation. */
    public static function defaultDir(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/alert';
    }

    /**
     * Report a failed probe. Dispatches on the ok→failing edge and once more
     * when the outage outlives the escalation window.
     *
     * @param string               $source  stable key of the watched thing (e.g. 'api:axo3:units')
     * @param string               $code    machine-readable cause (envelope error code, 'network', …)
     * @param array<string,string> $context extra facts for the message (url, tenant, …)
     * @return AlertKind|null what was dispatched, null when this failure stays silent
     */
    public function failure(string $source, string $code, array $context = [], ?int $now = null): ?AlertKind
    {
        $now ??= time();
        $state = $this->read($source);

        if (($state['status'] ?? 'ok') !== 'failing') {
            $state = [
                'source'        => $source,
                'status'        => 'failing',
                'failing_since' => $now,
                'last_success'  => $state['last_success'] ?? null,
                'escalated'     => false,
                'code'          => $code,
            ];
            $this->write($source, $state);
            return $this->dispatch(AlertKind::Outage, $state, $code, $context, $now);
        }

        // Still failing — silent, unless the escalation window just ran out.
        $state['code'] = $code;
        if (!($state['escalated'] ?? false)
            && ($now - (int) $state['failing_since']) >= $this->escalationSeconds
        ) {
            $state['escalated'] = true;
            $this->write($source, $state);
            return $this->dispatch(AlertKind::Escalation, $state, $code, $context, $now);
        }

        $this->write($source, $state);
        return null;
    }

    /**
     * Report a successful probe. Dispatches on the failing→ok edge; otherwise
     * it only refreshes `last_success` (the age anchor of later messages).
     *
     * @return AlertKind|null AlertKind::Recovery when an outage just ended
     */
    public function success(string $source, array $context = [], ?int $now = null): ?AlertKind
    {
        $now ??= time();
        $state = $this->read($source);

        $wasFailing = ($state['status'] ?? 'ok') === 'failing';
        $recovered  = $wasFailing ? $state : null;

        $this->write($source, [
            'source'        => $source,
            'status'        => 'ok',
            'failing_since' => null,
            'last_success'  => $now,
            'escalated'     => false,
            'code'          => '',
        ]);

        if ($recovered === null) {
            return null;
        }

        return $this->dispatch(
            AlertKind::Recovery,
            $recovered,
            (string) ($recovered['code'] ?? ''),
            $context,
            $now
        );
    }

    private function dispatch(AlertKind $kind, array $state, string $code, array $context, int $now): AlertKind
    {
        $message = new AlertMessage(
            $kind,
            (string) $state['source'],
            $code,
            $now,
            isset($state['failing_since']) ? (int) $state['failing_since'] : null,
            isset($state['last_success']) ? (int) $state['last_success'] : null,
            $context
        );

        foreach ($this->channels as $channel) {
            try {
                $channel->send($message);
            } catch (\Throwable $e) {
                // One dead channel must neither kill the others nor the caller.
                error_log('alert: channel ' . $channel::class . ' failed: ' . $e->getMessage());
            }
        }

        return $kind;
    }

    private function read(string $source): array
    {
        $file = $this->file($source);
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private function write(string $source, array $state): void
    {
        if (!is_dir($this->stateDir) && !mkdir($this->stateDir, 0755, true) && !is_dir($this->stateDir)) {
            // Loud, not silent — a dangling var/lib symlink would otherwise
            // swallow every state change and with it every future alert.
            throw new \RuntimeException("AlertService: could not create state directory {$this->stateDir}");
        }
        file_put_contents(
            $this->file($source),
            json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function file(string $source): string
    {
        return $this->stateDir . '/' . hash('sha256', $source) . '.json';
    }
}
