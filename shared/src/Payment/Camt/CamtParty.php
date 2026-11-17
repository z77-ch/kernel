<?php

namespace Z77\Shared\Payment\Camt;

/**
 * A related party of a transaction (`RltdPties/Dbtr`, `UltmtDbtr`, `Cdtr`, `UltmtCdtr`) as the
 * file names it — data only. `iban` is the party's account (`DbtrAcct` / `CdtrAcct`), normalised,
 * '' when the file carries none (an ultimate party never has one).
 */
final class CamtParty
{
    public function __construct(
        public readonly string $name,
        public readonly string $city,
        public readonly string $iban = '',
    ) {}
}
