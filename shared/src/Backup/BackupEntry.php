<?php

namespace Z77\Shared\Backup;

/**
 * One backup on disk — a plain read model assembled by {@see BackupHistory} from
 * the archive file plus its optional `*.meta.json` sidecar. The file system is
 * the single source of truth (no central history file, ADR-025 philosophy):
 * size and creation time come from the file itself, only run details
 * (trigger, duration, file count) come from the sidecar.
 */
final class BackupEntry
{
    public function __construct(
        private BackupType $type,
        private string $fileName,
        private \DateTimeImmutable $createdAt,
        private int $sizeBytes,
        private array $meta = [],
    ) {}

    public function getType(): BackupType
    {
        return $this->type;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    /** Run details from the meta sidecar: trigger, duration_seconds, status, files. */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /** 'manual' | 'cron' | '' when the sidecar is missing. */
    public function getTrigger(): string
    {
        return (string)($this->meta['trigger'] ?? '');
    }
}
