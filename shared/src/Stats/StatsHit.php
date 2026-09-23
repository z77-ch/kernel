<?php

namespace Z77\Shared\Stats;

/**
 * One countable request, as facts — before classification, before hashing.
 *
 * This is the recorder's INPUT, not what is stored: it still carries the
 * address and the user agent, because the visitor key, the country and the
 * device class are derived from them at request time. {@see StatsRecorder}
 * turns it into a line that carries none of the three. Nothing outside the
 * recorder keeps a StatsHit — it is built, handed over, and gone.
 *
 * `path` is the canonical path (language prefix stripped, the alias path
 * after an alias hit — `/kontakt` for both `/kontakt` and `/fr/contact`);
 * `query` is the raw query map, of which only the campaign keys survive.
 */
final class StatsHit
{
    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string  $path,
        public readonly array   $query,
        public readonly int     $status,
        public readonly string  $lang,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $referer,
        public readonly string  $host,
        public readonly string  $event = StatsRecorder::PAGE_EVENT,
    ) {}
}
