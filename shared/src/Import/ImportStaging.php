<?php

namespace Z77\Shared\Import;

/**
 * The staging area (ADR-032 §14): a fixed, framework-owned directory under
 * `data/framework/import/` — the ONLY place the import reads source files
 * from. The screen lists it; it never accepts a path (no user-supplied paths,
 * IMP-R020).
 *
 *   inbox/      developer drops files here (FTP/Explorer) — large dumps that
 *               would blow the upload limit
 *   snapshots/  staged sources: {ts}_{hash8}_{name} + index.json — the frozen
 *               bytes a plan and a job-based apply run against (propbase
 *               pattern: raw snapshot + hash + index)
 *   plans/      the persisted plan state ({@see ImportPlanStore})
 *   import.lock one apply at a time
 *
 * Everything here is transient state: excluded from backup archives
 * (BACKUP-JOBS-001 precedent) and deleted on apply/discard.
 */
final class ImportStaging
{
    private const INDEX_FILE = 'index.json';

    public function __construct(private readonly string $importDir)
    {
    }

    public function getImportDir(): string { return $this->importDir; }
    public function getPlansDir(): string { return $this->importDir . '/plans'; }

    // -------------------------------------------------------------------------
    // Inbox
    // -------------------------------------------------------------------------

    /** @return list<array{name: string, size: int, mtime: int}> JSON files in the inbox */
    public function listInbox(): array
    {
        $dir = $this->importDir . '/inbox';
        if (!is_dir($dir)) return [];

        $files = [];
        foreach (scandir($dir) ?: [] as $item) {
            $path = $dir . '/' . $item;
            if ($item === '.' || $item === '..' || !is_file($path)) continue;
            $files[] = ['name' => $item, 'size' => (int) filesize($path), 'mtime' => (int) filemtime($path)];
        }
        return $files;
    }

    /**
     * Freezes an inbox file into a snapshot (moves it — the inbox is a mailbox,
     * not a library). $name is a basename from {@see listInbox()}, never a path.
     */
    public function stageInboxFile(string $name): string
    {
        $safe = $this->assertBasename($name);
        $path = $this->importDir . '/inbox/' . $safe;
        if (!is_file($path)) {
            throw new ImportSourceException("Inbox file not found: {$safe}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new ImportSourceException("Failed to read inbox file: {$safe}");
        }

        $snapshot = $this->writeSnapshot($content, $safe, 'inbox');
        unlink($path);
        return $snapshot;
    }

    /** Freezes uploaded bytes into a snapshot (uploads are staged on arrival, §14). */
    public function stageContent(string $content, string $origName): string
    {
        return $this->writeSnapshot($content, $this->assertBasename($origName), 'upload');
    }

    // -------------------------------------------------------------------------
    // Snapshots
    // -------------------------------------------------------------------------

    /** @return list<array{file: string, orig: string, origin: string, hash: string, created_at: string}> */
    public function listSnapshots(): array
    {
        $index = $this->readIndex();
        return array_values($index);
    }

    /** Absolute path of a snapshot listed in the index. $name is a basename, never a path. */
    public function snapshotPath(string $name): string
    {
        $safe = $this->assertBasename($name);
        if (!isset($this->readIndex()[$safe])) {
            throw new ImportSourceException("Unknown snapshot: {$safe}");
        }
        return $this->importDir . '/snapshots/' . $safe;
    }

    /** Deletes a snapshot + its index entry (apply done or source discarded). */
    public function discard(string $name): void
    {
        $safe  = $this->assertBasename($name);
        $index = $this->readIndex();
        if (!isset($index[$safe])) return;

        $path = $this->importDir . '/snapshots/' . $safe;
        if (is_file($path)) unlink($path);
        unset($index[$safe]);
        $this->writeIndex($index);
    }

    // -------------------------------------------------------------------------
    // Apply lock — one import apply at a time
    // -------------------------------------------------------------------------

    /**
     * Runs $work under the exclusive, non-blocking import lock. A second
     * concurrent apply gets an exception instead of interleaved writes. The
     * OS lock dies with its process (same reasoning as the job locks, ADR-031).
     */
    public function withApplyLock(callable $work): mixed
    {
        $this->ensureDir($this->importDir);
        $lockPath = $this->importDir . '/import.lock';

        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open import lock: {$lockPath}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another import apply is already running.');
        }
        try {
            return $work();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function writeSnapshot(string $content, string $origName, string $origin): string
    {
        $dir = $this->importDir . '/snapshots';
        $this->ensureDir($dir);

        $hash = hash('sha256', $content);
        $file = date('Ymd-His') . '_' . substr($hash, 0, 8) . '_' . $origName;

        if (file_put_contents($dir . '/' . $file, $content) === false) {
            throw new \RuntimeException("Failed to write snapshot: {$file}");
        }

        $index = $this->readIndex();
        $index[$file] = [
            'file'       => $file,
            'orig'       => $origName,
            'origin'     => $origin,
            'hash'       => $hash,
            'created_at' => date(DATE_ATOM),
        ];
        $this->writeIndex($index);

        return $file;
    }

    /** @return array<string, array<string, mixed>> keyed by snapshot file name */
    private function readIndex(): array
    {
        $path = $this->importDir . '/snapshots/' . self::INDEX_FILE;
        if (!is_file($path)) return [];

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeIndex(array $index): void
    {
        $dir = $this->importDir . '/snapshots';
        $this->ensureDir($dir);
        file_put_contents(
            $dir . '/' . self::INDEX_FILE,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

    /** Rejects anything that is not a plain file name — the no-paths rule (IMP-R020). */
    private function assertBasename(string $name): string
    {
        $safe = basename(trim($name));
        if ($safe === '' || $safe !== trim($name) || str_starts_with($safe, '.')) {
            throw new ImportSourceException("Invalid file name: {$name}");
        }
        return $safe;
    }
}
