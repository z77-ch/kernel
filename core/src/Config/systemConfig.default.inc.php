<?php
// Default System Config (ADR-030)
//
// Settings that describe THIS installation — not the project's code, not a
// user's preference. Seeded once into config/client/systemConfig.inc.php and never
// overwritten, so a value set on the server survives `composer install`.
//
// Bootstrap publishes these as constants, which makes them readable from a web
// request and from a cron entry that boots the framework alike.
return [
    // Absolute origin of this installation, e.g. 'https://kunde.ch' — the source
    // for every URL generated to leave the request: mail links (magic login,
    // registration confirmation, activation) and the SEO canonical/hreflang set.
    //
    // There is no sensible default: any guessed host would be wrong, and wrong
    // invisibly. Empty therefore does NOT abort the boot — the backend must stay
    // reachable to fix it, and the shell shows a banner — but the first attempt
    // to build an absolute URL throws (ADR-030, point 4).
    //
    // Deriving it from the Host header instead is what SEC-005 was about: the
    // client sends that header, and the page cache keys on path only.
    'canonicalBaseUrl' => '',

    // ISO 4217 code of the currency this installation keeps its books in. The
    // database stores amounts without a currency (ADR-042 decision 4: the
    // ledger is base-currency only), so every DECIMAL money column is read in
    // this one. Obvious default (ADR-030, point 4): every installation books
    // in CHF; an installation whose seed-once file predates the key gets it.
    'baseCurrency'     => 'CHF',
];
