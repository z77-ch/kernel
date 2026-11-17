<?php

namespace Z77\Shared\Payment\Camt;

/**
 * One transaction of an entry (`Ntry/NtryDtls/TxDtls`) — data only, nothing resolved. A plain
 * booking has one, a batch booking one per payment, and a bank may deliver an entry without any.
 *
 * Amounts are two-decimal strings (no float, ADR-042); `amount` / `currency` are null / '' when
 * the transaction states none (the entry's amount applies). `instructedAmount` is the amount the
 * payer instructed (`AmtDtls/InstdAmt`) in `instructedCurrency`; `exchangeRate` the rate of its
 * `CcyXchg` exactly as the file writes it ('' without one), `exchangeSource` / `exchangeTarget`
 * its currencies.
 */
final class CamtTransaction
{
    public function __construct(
        public readonly string $accountServicerRef,
        public readonly string $txId,
        public readonly string $endToEndId,
        public readonly ?string $amount,
        public readonly string $currency,
        public readonly ?string $instructedAmount,
        public readonly string $instructedCurrency,
        public readonly string $exchangeRate,
        public readonly string $exchangeSource,
        public readonly string $exchangeTarget,
        /** QRR | SCOR | NON | '' (no remittance information at all). */
        public readonly string $referenceType,
        public readonly string $reference,
        /** The unstructured message (`RmtInf/Ustrd` and `Strd/AddtlRmtInf`, joined). */
        public readonly string $remittance,
        public readonly ?CamtParty $debtor,
        public readonly ?CamtParty $ultimateDebtor,
        public readonly ?CamtParty $creditor,
        public readonly ?CamtParty $ultimateCreditor,
    ) {}
}
