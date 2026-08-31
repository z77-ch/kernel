<?php

namespace Z77\Shared\Forms;

use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Entities\BlockedCountry;

/**
 * The countries whose submits to geo-guarded public forms are refused — read
 * by the gate in {@see PublicFormHandler}, written by the backend surface
 * that shows the form log.
 *
 * ⚠️ A BLOCKLIST, never a whitelist. A whitelist locks out the Swiss customer
 * sitting in a holiday WLAN, the one on a VPN and the one whose carrier
 * routes through Frankfurt — all of them real, none of them visible to us as
 * «CH». A blocklist can only ever be too small, which costs an attempt we
 * would have had anyway; a whitelist can be too small in a way that costs a
 * customer.
 *
 * ⚠️ {@see self::codes()} NEVER throws. It sits in the path of a public
 * form, so an unreadable store means the rule is OFF, not that everyone is
 * barred. Same stance as the whole GeoIP layer: an optional extra must never
 * be the reason a customer cannot submit. Failing open is the deliberate
 * choice — this rule limits abuse, it does not guard anything secret.
 *
 * The WRITE side ({@see self::block()} / {@see self::unblock()}) does not
 * swallow anything: that runs in the backend, where a failed save has to be
 * visible.
 */
final class CountryBlocklist
{
    public function __construct(private UnifiedEntityManager $uem)
    {
    }

    /** Production wiring — file persistence, same resolver as the entity stores. */
    public static function create(): self
    {
        return new self(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
    }

    /**
     * Just the codes, for the gate.
     *
     * @return list<string> upper-case, two letters, duplicates removed
     */
    public static function codes(): array
    {
        try {
            $codes = [];
            foreach (self::create()->all() as $entry) {
                $code = $entry->getCode();
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }

            return array_keys($codes);
        } catch (\Throwable) {
            return [];   // see the class docblock: the rule fails OPEN
        }
    }

    /**
     * Every entry, by code — the order the backend list shows and the order a
     * human scans.
     *
     * @return list<BlockedCountry>
     */
    public function all(): array
    {
        $entries = array_values(array_filter(
            $this->repository()->findAll(),
            static fn(mixed $e): bool => $e instanceof BlockedCountry,
        ));

        usort(
            $entries,
            static fn(BlockedCountry $a, BlockedCountry $b): int
                => strcmp($a->getCode(), $b->getCode()),
        );

        return $entries;
    }

    public function find(string $code): ?BlockedCountry
    {
        $code = BlockedCountry::normalizeCode($code);
        if ($code === '') {
            return null;
        }

        $entry = $this->repository()->findOneBy(['code' => $code]);

        return $entry instanceof BlockedCountry ? $entry : null;
    }

    public function has(string $code): bool
    {
        return $this->find($code) !== null;
    }

    /**
     * Adds a country, or returns null when the code is no country code or the
     * country is already listed. A second entry for the same code would make
     * «aufheben» ambiguous, so it is refused rather than deduplicated later.
     */
    public function block(string $code, string $reason, ?string $addedBy = null, ?int $now = null): ?BlockedCountry
    {
        $code = BlockedCountry::normalizeCode($code);
        if ($code === '' || $this->has($code)) {
            return null;
        }

        $entry = new BlockedCountry();
        $entry->setCode($code);
        $entry->setReason(trim($reason));
        $entry->setAddedBy($addedBy);
        $entry->setAddedAt(date(DATE_ATOM, $now ?? time()));

        $this->uem->persist($entry);
        $this->uem->flush();

        return $entry;
    }

    /** True when an entry was removed. */
    public function unblock(string $code): bool
    {
        $entry = $this->find($code);
        if ($entry === null) {
            return false;
        }

        $this->uem->remove($entry);
        $this->uem->flush();

        return true;
    }

    private function repository(): object
    {
        return $this->uem->getRepository(BlockedCountry::class);
    }
}
