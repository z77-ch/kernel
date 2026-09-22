<?php
// Default Database Config (ADR-039) — the ONE place the relational connection
// lives. Seed-once: written to config/client/database.inc.php on the first
// install and never overwritten. Deliberately NOT fed from composer.json —
// that file is committed, and staging and production must not share a set of
// credentials (the ADR-030 argument for systemConfig).
//
// Read by the Doctrine driver (z77/persistence-doctrine) and by the `db`
// backup (BackupService → mysqldump). An empty 'name' means: no database —
// the framework itself is file-based and runs without one.
//
// Engine: MariaDB 10.6 or newer, InnoDB (ADR-039 decision 17). The charset and
// collation are not configurable — utf8mb4 / utf8mb4_unicode_ci throughout,
// set by the driver on every connection (decision 18).
return [
    'host'     => 'localhost',
    'port'     => null,          // null = the server default (3306)
    'name'     => '',
    'user'     => '',
    'password' => '',
];
