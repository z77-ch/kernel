<?php

namespace Z77\Shared\Alert;

/**
 * Everything a channel needs to render an actionable alert without a
 * follow-up question (per the alarm handoff): which source, the error code,
 * failing since when, when the last success was — and how OLD the data being
 * served is now, because that is what carries the urgency: a two-hour-old
 * stand is a note, a two-week-old one is an incident.
 */
final class AlertMessage
{
    /**
     * @param array<string,string> $context free extra facts (url, tenant, …),
     *        rendered as-is by the channels
     */
    public function __construct(
        public readonly AlertKind $kind,
        public readonly string $source,
        public readonly string $code,
        public readonly int $now,
        public readonly ?int $failingSince,
        public readonly ?int $lastSuccess,
        public readonly array $context = [],
    ) {
    }

    /** Seconds the source has been failing; null when not failing. */
    public function failingFor(): ?int
    {
        return $this->failingSince === null ? null : max(0, $this->now - $this->failingSince);
    }

    /** Age of the last successfully fetched stand in seconds; null when unknown. */
    public function staleFor(): ?int
    {
        return $this->lastSuccess === null ? null : max(0, $this->now - $this->lastSuccess);
    }
}
