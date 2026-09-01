<?php
// Default Bootstrap Config
return [
    'cacheDebug' => false,
    'htmlRoot' => 'public',
    // NOTE: no 'cacheDir' here since ADR-035. The page cache, the APCu stamp and
    // the DEBUG / SEO_NOINDEX flags live at fixed release-local paths (`var/cache`,
    // `var/state`, published as ABS_VAR_PATH / ABS_STATE_PATH in Bootstrap). They
    // are part of the release-structure contract, not a project setting: a
    // configurable path could be pointed back into a shared store, which is the
    // defect ADR-035 removes. A leftover 'cacheDir' key in an installed
    // config/bootstrap.inc.php is ignored — see docs/01-handbook/release-structure.md.
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
