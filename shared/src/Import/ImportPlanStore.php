<?php

namespace Z77\Shared\Import;

/**
 * Persists the CURRENT import operation between requests (and into a job-based
 * apply): source spec, the developer's decisions, and the target fingerprints
 * from plan time (IMP-R011). Deliberately NOT the computed plan — the plan is
 * recomputed deterministically from snapshot + decisions on every load, so an
 * assignment can unblock dependents without patch logic. One operation at a
 * time by design: a new plan replaces the current one.
 *
 * State shape:
 *   created_at    ISO timestamp
 *   source        {type: 'vendor'|'snapshot', label, files: {entityClass: absolutePath}}
 *   decisions     {"{class}#{sourceIndex}": {decision?, target_id?}}
 *   fingerprints  {entityClass: sha1 of the target record set at plan time}
 */
final class ImportPlanStore
{
    private const FILE = 'current.json';

    public function __construct(private readonly string $plansDir)
    {
    }

    public function save(array $state): void
    {
        if (!is_dir($this->plansDir) && !mkdir($this->plansDir, 0775, true) && !is_dir($this->plansDir)) {
            throw new \RuntimeException("Failed to create plans directory: {$this->plansDir}");
        }
        $path = $this->plansDir . '/' . self::FILE;
        if (file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n") === false) {
            throw new \RuntimeException("Failed to write plan state: {$path}");
        }
    }

    public function load(): ?array
    {
        $path = $this->plansDir . '/' . self::FILE;
        if (!is_file($path)) return null;

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function clear(): void
    {
        $path = $this->plansDir . '/' . self::FILE;
        if (is_file($path)) unlink($path);
    }
}
