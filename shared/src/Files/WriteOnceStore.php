<?php

namespace Z77\Shared\Files;

/**
 * A WRITE-ONCE file store under the installation root — the building block of the archives that
 * keep a booking record unaltered for its retention period (a bank file read in, ten years).
 * The caller owns the path scheme and the row that names the file (path + SHA-256); this class
 * owns how a file gets there and how it is read back:
 *
 *   - {@see store()} writes under a temporary name, reads the bytes back, compares the SHA-256
 *     and only then moves the file into place. A path that already holds the SAME bytes is
 *     accepted (idempotent); one that holds OTHER bytes is refused — an archived file is never
 *     overwritten;
 *   - there is NO delete path. The one exception is {@see discardWritten()}: the files THIS
 *     instance wrote, for a caller whose unit of work rolled back after {@see store()}, so that
 *     no file lies there that no row names;
 *   - {@see bytes()} answers the file only when its SHA-256 matches the row's — a missing or
 *     altered file is null, never silently accepted.
 *
 * Lifted on 2026-11-17 (ADR-061 stage C3, debtor's camt.054 archive) from financial's
 * `Bank\BankStatementArchive`, which follows the same rules for the camt.053 (Rule 9: the third
 * archive of the kind — after debtor's invoice PDFs and financial's statements — made it a
 * shared block). Non-public by its location: the callers write below `data/`, which is never
 * served and lies in the release's shared `data` store and the «Daten» backup.
 */
final class WriteOnceStore
{
    private readonly string $base;

    /** @var list<string> absolute paths written by this instance — removed by {@see discardWritten()} */
    private array $written = [];

    /** @param string|null $basePath the installation root; null = `ABS_BASE_PATH` */
    public function __construct(?string $basePath = null)
    {
        $base = $basePath ?? (defined('ABS_BASE_PATH') ? (string) constant('ABS_BASE_PATH') : '');
        if ($base === '') {
            throw new \LogicException('WriteOnceStore needs the installation root (ABS_BASE_PATH)');
        }
        $this->base = rtrim(str_replace('\\', '/', $base), '/');
    }

    /**
     * Stores $bytes at $relative (relative to the installation root) — see the class docblock.
     *
     * @throws \RuntimeException the checksum is not the bytes', the path holds other bytes, or the
     *                           file could not be written or verified — the caller's unit of work rolls back
     */
    public function store(string $relative, string $bytes, string $sha256): void
    {
        $absolute = $this->absolute($relative);
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException("WriteOnceStore: the checksum given for {$relative} is not the checksum of the bytes");
        }
        if (is_file($absolute)) {
            if (!hash_equals($sha256, (string) @hash_file('sha256', $absolute))) {
                throw new \RuntimeException("WriteOnceStore: {$relative} exists with other content — an archived file is never overwritten");
            }

            return;
        }
        $dir = dirname($absolute);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("WriteOnceStore: cannot create the directory {$dir}");
        }
        $temp = $absolute . '.' . bin2hex(random_bytes(4)) . '.tmp';
        try {
            if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !hash_equals($sha256, (string) @hash_file('sha256', $temp))) {
                throw new \RuntimeException("WriteOnceStore: cannot write {$relative}");
            }
            if (!@rename($temp, $absolute)) {
                throw new \RuntimeException("WriteOnceStore: cannot move {$relative} into place");
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        $this->written[] = $absolute;
    }

    /** The bytes at $relative when their SHA-256 is $sha256; null when the file is missing or altered. */
    public function bytes(string $relative, string $sha256): ?string
    {
        $absolute = $this->absolute($relative);
        $bytes    = is_file($absolute) ? @file_get_contents($absolute) : false;

        return $bytes !== false && hash_equals($sha256, hash('sha256', $bytes)) ? $bytes : null;
    }

    /** Whether a file lies at $relative — tells «missing» from «altered» after {@see bytes()} answered null. */
    public function exists(string $relative): bool
    {
        return is_file($this->absolute($relative));
    }

    /** Removes the files this instance wrote — for a caller whose unit of work rolled back after {@see store()}. */
    public function discardWritten(): void
    {
        foreach ($this->written as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->written = [];
    }

    private function absolute(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || in_array('..', explode('/', $relative), true)) {
            throw new \InvalidArgumentException("WriteOnceStore: not a path below the installation root: {$relative}");
        }

        return $this->base . '/' . $relative;
    }
}
