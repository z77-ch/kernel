<?php

namespace Z77\Shared\Payment\Camt;

/**
 * A camt account-report message as {@see CamtParser} read it: which kind it is, its header and
 * its reports. Data only — what an entry MEANS is the consumer's.
 */
final class CamtMessage
{
    public const KIND_REPORT       = 'camt.052';
    public const KIND_STATEMENT    = 'camt.053';
    public const KIND_NOTIFICATION = 'camt.054';

    /** @param list<CamtReport> $reports */
    public function __construct(
        public readonly string $kind,
        public readonly string $messageId,
        public readonly ?\DateTimeImmutable $createdOn,
        public readonly array $reports,
    ) {}
}
