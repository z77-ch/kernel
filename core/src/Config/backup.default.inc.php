<?php
// Default Backup Config — installation-wide backup policy (docs/topics/backup.md).
// Seed-once: written to config/backup.inc.php on the first install and never
// overwritten — adapt retention / excludes / database per installation there.
return [
    // Backup root, relative to the project root. MUST stay outside the web root
    // (htmlRoot) — archives contain data/framework/auth/loginUsers.json.
    'dir'          => 'backup',

    // Kept archives per type after each run; 0 = unlimited (no cleanup).
    'retention'    => [
        'data' => 10,
        'db'   => 10,
        'full' => 5,
    ],

    // Project-relative paths excluded from the `full` backup. vendor/ and
    // node_modules/ are regenerable from composer.lock / package-lock.json;
    // the backup dir itself is always excluded (recursion guard), listing it
    // here just documents that.
    'fullExcludes' => ['vendor', 'node_modules', 'backup', 'lib/cache'],

    // Database for the `db` backup type — null = no database (the default;
    // the framework itself is file-based). To enable:
    // 'database' => [
    //     'driver'    => 'mysql',
    //     'host'      => 'localhost',
    //     'port'      => null,          // optional
    //     'name'      => 'my_database',
    //     'user'      => 'backup_user',
    //     'pass'      => 'secret',
    //     'mysqldump' => 'mysqldump',   // binary, override when not on PATH
    // ],
    'database'     => null,
];
