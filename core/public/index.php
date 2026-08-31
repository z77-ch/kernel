<?php
/**
 * Front controller — a STABLE trampoline. Do not add logic here.
 *
 * OPcache binds the door path (e.g. next/index.php) to ONE compiled copy of
 * this file and never re-resolves the symlink (opcache.revalidate_path=0),
 * so after a release switch the OLD compiled copy keeps running — measured
 * on cyon 2026-08-31. Therefore the release root is resolved at RUNTIME from
 * DOCUMENT_ROOT (fresh from the web server on every request), never baked in
 * via __DIR__: even a stale compiled copy then boots the CURRENT release
 * (within the realpath cache TTL, <=120 s). This is also why this file must
 * stay minimal and unchanging — the cached copy may outlive the release it
 * came from.
 *
 * $_SERVER is allowed here as the one exception to the Request-only rule:
 * this runs before the framework exists.
 */
$docroot    = $_SERVER['DOCUMENT_ROOT'] ?? '';
$publicPath = $docroot !== '' ? realpath($docroot) : false;
if ($publicPath === false) {
    $publicPath = __DIR__; // local dev / no symlink: the file's own path is the truth
}

define('ABS_BASE_PATH', str_replace('\\', '/', dirname($publicPath)));
define('ABS_INDEX_PATH', str_replace('\\', '/', $publicPath));

require_once ABS_BASE_PATH.'/vendor/autoload.php';

use Z77\Core\Bootstrap;

$bootstrap = new Bootstrap();
$dispatcher = $bootstrap->pullUp();
$dispatcher->execute();
