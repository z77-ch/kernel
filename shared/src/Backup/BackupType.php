<?php

namespace Z77\Shared\Backup;

/**
 * The three backup families. The enum value doubles as the storage directory
 * name under the backup root (`backup/{value}/`) and as the type token in the
 * archive file name (`YYYY-MM-DD_HHMMSS_{value}.zip`) — see docs/topics/backup.md.
 */
enum BackupType: string
{
    case Data = 'data';
    case Db   = 'db';
    case Full = 'full';

    /** Parses a user/CLI-supplied type token; null for anything unknown. */
    public static function fromName(?string $name): ?self
    {
        return self::tryFrom(strtolower(trim((string)$name)));
    }
}
