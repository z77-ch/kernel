<?php

namespace Z77\Shared\Files;

/**
 * Turns a path a user stored (e.g. a Drive path `media/public/imgs/logo.png`) into a readable file
 * on this server — or null when it is not this resolver's kind of path or names nothing. Registered
 * under the config key `localFileResolvers`, asked by {@see LocalFiles::path()}.
 */
interface LocalFileResolver
{
    public function resolve(string $path): ?string;
}
