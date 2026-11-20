<?php

namespace Z77\Shared\Auth;

/**
 * «Chrome auf Windows» — what a list of remembered devices shows per entry, read from the
 * User-Agent at the moment the device was remembered. A hint for the person, never an identity:
 * the header is whatever the client sends.
 */
final class DeviceLabel
{
    public static function fromUserAgent(string $userAgent): string
    {
        $ua = trim($userAgent);
        if ($ua === '') {
            return 'Unbekanntes Gerät';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')                              => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera')  => 'Opera',
            str_contains($ua, 'Firefox/')                          => 'Firefox',
            str_contains($ua, 'Chrome/')                           => 'Chrome',
            str_contains($ua, 'Safari/')                           => 'Safari',
            default                                                => 'Browser',
        };

        $system = match (true) {
            str_contains($ua, 'Windows')                                => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad')      => 'iOS',
            str_contains($ua, 'Android')                                => 'Android',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                                  => 'Linux',
            default                                                     => 'unbekanntem System',
        };

        return $browser . ' auf ' . $system;
    }
}
