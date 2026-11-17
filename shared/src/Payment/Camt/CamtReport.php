<?php

namespace Z77\Shared\Payment\Camt;

/**
 * One account report inside a camt message: a `Stmt` (camt.053), a `Ntfctn` (camt.054) or a
 * `Rpt` (camt.052) — the account it reports on, its period, its balances (a notification has
 * none) and its entries. Data only. The IBAN is normalised (upper-case, no spaces).
 */
final class CamtReport
{
    /**
     * @param list<CamtBalance> $balances
     * @param list<CamtEntry>   $entries in file order
     */
    public function __construct(
        public readonly string $id,
        public readonly ?int $electronicSequence,
        public readonly ?int $legalSequence,
        public readonly ?\DateTimeImmutable $from,
        public readonly ?\DateTimeImmutable $to,
        public readonly string $iban,
        public readonly string $currency,
        public readonly array $balances,
        public readonly array $entries,
    ) {}

    /** The first balance of $type (`OPBD`, `CLBD` …), or null. */
    public function balance(string $type): ?CamtBalance
    {
        foreach ($this->balances as $balance) {
            if ($balance->type === $type) {
                return $balance;
            }
        }

        return null;
    }
}
