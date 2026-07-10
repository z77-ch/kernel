<?php
// Default i18n Config (ADR-013)
// Single source of truth for the system's language policy:
//   defaultLanguage — the one system-wide fallback (the hebel for "set all to X")
//   languages       — globally available set + validation whitelist for routing
return [
    'defaultLanguage' => 'de',
    'languages'       => ['de', 'en', 'fr'],
];
