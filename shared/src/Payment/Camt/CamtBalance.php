<?php

namespace Z77\Shared\Payment\Camt;

/**
 * One balance of a statement (`Stmt/Bal`): the type code (`OPBD` opening booked, `CLBD` closing
 * booked, `CLAV` closing available, `ITBD` interim booked …), its date, and the amount as a
 * SIGNED two-decimal string — negative when the file marks it `DBIT`.
 */
final class CamtBalance
{
    public const OPENING_BOOKED = 'OPBD';
    public const CLOSING_BOOKED = 'CLBD';

    public function __construct(
        public readonly string $type,
        public readonly ?\DateTimeImmutable $date,
        public readonly string $amount,
        public readonly string $currency,
    ) {}
}
