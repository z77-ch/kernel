<?php

namespace Z77\Shared\Payment\Camt;

/**
 * One booking on the account (`Ntry`) — data only. The amount is unsigned; `isCredit` gives the
 * side. `status` is `BOOK` for a booked entry (`Sts/Cd` in .08, `Sts` in .04). `bankTxCode` is
 * the ISO bank transaction code as `Domain/Family/SubFamily` (`PMNT/CCRD/POSD`), '' without one.
 * `batchCount` is `NtryDtls/Btch/NbOfTxs` (null when not stated); `transactions` the `TxDtls`
 * in file order — possibly empty.
 */
final class CamtEntry
{
    public const STATUS_BOOKED = 'BOOK';

    /** @param list<CamtTransaction> $transactions */
    public function __construct(
        public readonly string $amount,
        public readonly string $currency,
        public readonly bool $isCredit,
        public readonly bool $isReversal,
        public readonly string $status,
        public readonly ?\DateTimeImmutable $bookingDate,
        public readonly ?\DateTimeImmutable $valueDate,
        public readonly string $accountServicerRef,
        public readonly string $bankTxCode,
        public readonly string $additionalInfo,
        public readonly ?int $batchCount,
        public readonly array $transactions,
    ) {}
}
