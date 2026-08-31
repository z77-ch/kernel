<?php
/**
 * Cron entry for hosts whose panel takes ONE command and no `cd` (cyon):
 *
 *   php84 /path/to/project/cron/run.php            the cron pass
 *   php84 .../cron/run.php --list                  registry + queue overview
 *
 * Why this exists: `z77-run` resolves the project root upward from the
 * WORKING DIRECTORY (deliberately — in monorepo development its own location
 * is a link into the framework), and a panel cron starts in the home
 * directory, where that walk finds nothing. This file sits physically in the
 * project, so PHP has already resolved any symlink in the CALL path by the
 * time it runs, and `__DIR__` names the real project. It sets the directory
 * and hands over — no logic of its own.
 *
 * Release layout (docs/01-handbook/release-structure.md): call it THROUGH
 * the switch — `<domain>/current/cron/run.php`. The link resolves at process
 * start, so a release switch is picked up by the next cron tick while a
 * running pass finishes on the release it started in.
 *
 * ⚠️ Never reach the project with `..` ACROSS the switch instead
 * (`--project=<domain>/current/..`): POSIX resolves the link component
 * first, so that path names `releases/`, not the project — the failures it
 * produces (job-lock mkdir errors far from the cause) do not mention this.
 *
 * Seeded once by the installer into `<project>/cron/run.php`; owned by the
 * project afterwards. ⚠️ The copy inside the kernel package is only the
 * TEMPLATE — never wire a cron to it there: `dirname(__DIR__)` would name the
 * package (in monorepo development even the framework, through the path-repo
 * link), not the project. The seeded copy in the project is the entry.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir(dirname(__DIR__));

require dirname(__DIR__) . '/vendor/bin/z77-run';
