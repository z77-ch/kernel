<?php

namespace Z77\Shared\Mail;

/**
 * What counts as an e-mail address in this framework — ONE notion, because
 * two notions drift.
 *
 * It used to live twice, copied character for character: `EntityValidator::
 * isEmail()` for stored entities and `PublicFormValidator::isEmailAddress()`
 * for public forms. Both now ask here.
 *
 * Two questions, and the difference matters:
 *
 *   isValid()        the shape is right — this is a well-formed address
 *   isDeliverable()  …and mail sent to it could actually arrive
 *
 * Forms want the second. A syntactically perfect address in a reserved TLD
 * accepts a registration, produces an unroutable confirmation mail, bounces
 * back at our own domain, and leaves a dead account in the operator's queue —
 * which is exactly what `terms-probe@example.invalid` did on 2026-08-26.
 */
final class MailAddress
{
    /**
     * Top-level domains the standards permanently reserve so that they can
     * NEVER resolve (RFC 2606 / RFC 6761). Nothing addressed here reaches a
     * human, anywhere, ever — refusing them costs no real correspondent.
     *
     * ⚠️ TLDs only, deliberately. The reserved DOMAINS `example.com` /
     * `.net` / `.org` stay acceptable: they are the standard placeholders in
     * tests and documentation, and this framework's own harnesses use them.
     */
    public const RESERVED_TLDS = ['invalid', 'test', 'example', 'localhost', 'local'];

    /** Well-formed. Same acceptance both call sites had before. */
    public static function isValid(string $address): bool
    {
        $address = trim($address);

        return (bool) filter_var($address, FILTER_VALIDATE_EMAIL)
            && (bool) preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $address);
    }

    /** Well-formed AND not in a TLD that can never receive mail. */
    public static function isDeliverable(string $address): bool
    {
        return self::isValid($address) && !self::hasReservedTld($address);
    }

    /** True for `…@host.invalid`, `…@anything.test`, and the rest of the list. */
    public static function hasReservedTld(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        $host = mb_strtolower(rtrim(trim(substr($address, $at + 1)), '.'));
        $dot  = strrpos($host, '.');
        $tld  = $dot === false ? $host : substr($host, $dot + 1);

        return in_array($tld, self::RESERVED_TLDS, true);
    }
}
