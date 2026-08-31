<?php
// Default Bootstrap Config
return [
    'cacheDebug' => false,
    'htmlRoot' => 'public',
    'cacheDir' => 'lib/cache/',
    'apcuCachePrefix' => 'z77',
    'overrideDir' => 'override',
    'moduleDir' => 'module',
    'assetDir' => 'assets',
    'tplDir' => 'res/view/templates',
    'timeZone' => 'Europe/Zurich',
    // NOTE: the installation's canonical base URL lives in systemConfig, NOT here —
    // this file is regenerated on every install and fed from composer.json, so it
    // can hold neither a server-corrected nor a per-environment value (ADR-030).
];
