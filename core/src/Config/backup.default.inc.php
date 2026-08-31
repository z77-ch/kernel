<?php
// Default Backup Config — installation-wide backup policy (docs/topics/backup.md).
// Seed-once: written to config/backup.inc.php on the first install and never
// overwritten — adapt retention / excludes / database per installation there.
return [
    // Backup root, relative to the project root. MUST stay outside the web root
    // (htmlRoot) — archives contain data/framework/auth/backendUsers.json.
    'dir'          => 'backup',

    // What each run keeps of the older archives. Two forms:
    //
    //   'data' => 10          keep the newest 10 (0 = unlimited, no cleanup)
    //   'data' => [...]       tiered: all of the last days, one per week,
    //                         one per month … so a mistake noticed LATE
    //                         still has a clean state to restore — with
    //                         «newest N» every kept archive would already
    //                         carry it. Tiers: last / daily / weekly /
    //                         monthly / yearly, count per tier, 0 = that
    //                         tier unlimited. `last` protects the manual
    //                         backup taken just before a risky change from
    //                         the same-day scheduled run.
    //
    // A 'yearly' tier on the SAME server is belt without braces — the host
    // that loses the disk loses the archive with it. Long-term states belong
    // offsite (download an archive periodically); add 'yearly' here only
    // knowing that.
    'retention'    => [
        'data' => ['last' => 2, 'daily' => 7, 'weekly' => 4, 'monthly' => 12],
        'db'   => ['last' => 2, 'daily' => 7, 'weekly' => 4, 'monthly' => 12],
        'full' => ['last' => 1, 'weekly' => 4, 'monthly' => 6],
    ],

    // Project-relative paths excluded from the `full` backup. vendor/ and
    // node_modules/ are regenerable from composer.lock / package-lock.json;
    // lib/ is scratch space the installation rebuilds by itself (page cache,
    // throttle counters) and may be deleted at any time — the whole tree is
    // named, so anything added under it later is covered without an edit here;
    // the backup dir itself is always excluded (recursion guard), listing it
    // here just documents that.
    'fullExcludes' => ['vendor', 'node_modules', 'backup', 'lib'],

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
