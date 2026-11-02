<?php

namespace Z77\Core\Http\Response;

/**
 * Bytes that are addressable by position but exist as no single file — what
 * {@see ByteSourceResponse} answers from. First implementation: the virtual ZIP of the DMS
 * (`Z77\Module\Dms\Zip\ZipLayout`, DMS-ZIP-001).
 */
interface ByteSource
{
    /** The total size in bytes; never changes for one instance. */
    public function size(): int;

    /**
     * Write the bytes `$start`..`$end` (inclusive) to `$stream`.
     *
     * @param resource $stream
     * @throws \InvalidArgumentException a position outside the source
     * @throws \RuntimeException         the bytes cannot be read in full
     */
    public function emit(int $start, int $end, $stream): void;
}
