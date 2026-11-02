<?php

namespace Z77\Core\Http\Response;

/**
 * The ONE reading of a `Range` header: which bytes a client asks for, capped at what one
 * answer may carry. Moved out of {@see FileResponse} for {@see ByteSourceResponse}
 * (DMS-ZIP-001) — a caller that must know the slice BEFORE answering (a gate per piece) asks
 * here too, so the gate and the answer can never read a header differently.
 */
final class ByteRange
{
    /**
     * Most bytes ONE ranged answer (206) carries — DMS-CHUNK-DL-001 (owner decision: half the
     * host cap).
     *
     * The host caps a response body (on shared hosting about 500 MB; above, 413 — the limit
     * cannot be raised). Every single range is capped — open-ended `bytes=n-`,
     * explicit `a-b` and suffix `-k` alike: the answer is the FIRST slice of what was asked,
     * `Content-Range` names it and the true total, and the client asks on from there. A media
     * player (`bytes=0-`, Safari's explicit `0-<last>`) gets at most this much per answer,
     * streamed — it starts playing at once. A resumed native download (`bytes=n-`) is cut
     * only where it would have hit the 413 anyway. The chunked download
     * (`documents/chunk-download.js`, 32 MB pieces) stays far below it. A request WITHOUT
     * `Range` is unchanged: 200, the whole file.
     */
    public const MAX_BYTES = 256 * 1024 * 1024;

    /**
     * The byte slice a `Range` header asks for, capped at `$max` bytes (open-ended, explicit
     * and suffix alike — the first slice of what was asked). `$max` is a parameter only so
     * a harness can exercise the cap on a small file; production always uses {@see MAX_BYTES}.
     *
     * @return array{0: int, 1: int}|false|null `[start, end]` (inclusive) for a 206,
     *         `false` for an unsatisfiable range (416), `null` for no / an unparsable
     *         header (200, the whole file — a multi-range list included)
     */
    public static function parse(string $header, int $size, int $max = self::MAX_BYTES): array|false|null
    {
        if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }
        if ($m[1] === '' && $m[2] === '') {
            return false;
        }
        if ($m[1] === '') {
            // suffix: last N bytes
            $start = max(0, $size - (int) $m[2]);
            $end   = $size - 1;
        } else {
            $start = (int) $m[1];
            $end   = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        if ($start > $end || $start >= $size) {
            return false;
        }

        return [$start, min($end, $start + max(1, $max) - 1)];
    }
}
