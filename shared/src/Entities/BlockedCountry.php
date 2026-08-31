<?php

namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * One country whose submits to geo-guarded public forms are refused — an
 * ISO 3166-1 alpha-2 code plus the reason it was entered.
 *
 * ⚠️ This is INSTALLATION data, not configuration. It used to be a
 * memberConfig array, which meant a deploy for every decision an operator
 * makes while reading the form log — and the config's own comment already
 * said the list stays empty until an installation has a reason «read off the
 * log, not guessed». A default that is always empty is not a default; the
 * record belongs where the operator writes it.
 *
 * Consequence to know: no `.default.json`, and the class is deliberately NOT
 * registered in `importEntities`. A blocklist is per-installation evidence —
 * carrying one project's list into another would be exactly the guess the
 * whole design refuses.
 *
 * The store sits under `framework/forms/`, deliberately NOT beside the
 * MaxMind database in `framework/geoip/`: that file is licence-bound third
 * party data (no redistribution, no backup ride-along), this one is the
 * operator's own record — they share no directory.
 *
 * `reason` is the point of the record, not decoration. In a year «RU
 * gesperrt» says nothing; a sentence with the tally that justified the block
 * is a decision someone can review — and reverse.
 *
 * Not marked invalidatesCache: the rule runs inside a form submit, it never
 * renders into a cached page.
 */
#[Entity('file', 'framework/forms/blocked-countries.json')]
class BlockedCountry
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** Auto-increment, assigned by the store on first persist. */
    private ?int $id = null;

    /** Unique key, upper-case two letters — normalized at the setter. */
    #[Clean('text')]
    private string $code = '';

    /** Why this country is on the list. Free text, written by the operator. */
    #[Clean('text')]
    private string $reason = '';

    /** DATE_ATOM. */
    #[Clean('text')]
    private string $addedAt = '';

    /** Backend user name at the time — who to ask about the reason. */
    #[Clean('nullable', 'text')]
    private ?string $addedBy = null;

    public function getId(): ?int         { return $this->id; }
    public function getCode(): string     { return $this->code; }
    public function getReason(): string   { return $this->reason; }
    public function getAddedAt(): string  { return $this->addedAt; }
    public function getAddedBy(): ?string { return $this->addedBy; }

    public function setCode(string $code): void       { $this->code = self::normalizeCode($code); }
    public function setReason(string $reason): void   { $this->reason = $reason; }
    public function setAddedAt(string $addedAt): void { $this->addedAt = $addedAt; }
    public function setAddedBy(?string $addedBy): void { $this->addedBy = $addedBy; }

    /**
     * Upper-case two letters, or '' when the input is not a country code.
     *
     * Normalizing HERE means registration, lookup and hydration all compare
     * equal — the same reason MemberAccount::normalizeEmail() sits on the
     * entity rather than in one caller.
     */
    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
    }
}
