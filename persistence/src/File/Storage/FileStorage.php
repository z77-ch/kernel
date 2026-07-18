<?php

namespace Z77\Persistence\File\Storage;

class FileStorage
{
    private string $basePath;

    public function __construct(string $basePath = 'data')
    {
        $this->basePath = ABS_BASE_PATH.'/'.$basePath.'/';

        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Loads a data JSON file into an array.
     *
     * Missing file → []. Empty/whitespace file → [] (legitimately empty). A
     * leading UTF-8 BOM is tolerated (stripped before decode) — some external
     * editors/shells add one (see DATA-JSON-001, docs/topics/persistence-file.md).
     *
     * A NON-EMPTY file that fails to parse throws instead of silently returning
     * [] — a corrupt data file (e.g. a BOM'd or truncated navigation.json) must be
     * visible, never blank the entity set behind a silent []. This does NOT catch
     * mojibake double-encoding (that is valid UTF-8 and decodes fine) — that class
     * is a prevention/convention matter, not detectable on read.
     */
    public function load(string $path): array
    {
        $path = trim($path, '/');
        if (!file_exists($this->basePath.$path)) return [];

        $json = file_get_contents($this->basePath.$path);
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);   // tolerate a stray BOM
        if (trim($json) === '') return [];                    // legitimately empty

        $data = json_decode($json, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                "Corrupt data file '{$path}': " . json_last_error_msg()
            );
        }

        return is_array($data) ? $data : [];
    }

    public function save(string $path, array $data): void
    {
        $path = trim($path, '/');
        $full = $this->basePath.$path;

        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($full, $json, LOCK_EX);
    }

    public function delete(string $path): void
    {
        $path = trim($path, '/');
        $full = $this->basePath.$path;
        if (is_file($full)) {
            unlink($full);
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->basePath.trim($path, '/'));
    }

    /**
     * Lists all *.json files in a directory (document mode).
     *
     * @return string[] paths relative to the data root, usable with load()/delete()
     */
    public function list(string $dir): array
    {
        $dir  = trim($dir, '/');
        $full = $this->basePath.$dir;
        if (!is_dir($full)) {
            return [];
        }

        $files = glob($full.'/*.json') ?: [];
        sort($files);

        return array_map(fn($f) => $dir.'/'.basename($f), $files);
    }
}
