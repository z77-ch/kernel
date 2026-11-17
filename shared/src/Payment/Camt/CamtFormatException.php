<?php

namespace Z77\Shared\Payment\Camt;

/**
 * {@see CamtParser} or {@see CamtArchive} refused the bytes — not XML, not an ISO 20022 camt
 * account report, not a readable ZIP. `reason` is a stable code; `getMessage()` is German and
 * can be shown as it is. The consumer (debtor's camt.054 import, financial's camt.053 import)
 * translates it into its own refusal.
 */
final class CamtFormatException extends \RuntimeException
{
    public const NOT_XML  = 'not-xml';
    public const NOT_CAMT = 'not-camt';
    public const ARCHIVE  = 'archive';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
