<?php

namespace Z77\Shared\Import\Source;

use Z77\Shared\Import\ImportSource;
use Z77\Shared\Import\ImportSourceException;

/**
 * v1 reader: native entity JSON — the format the framework itself writes
 * (`*.default.json` seeds, runtime data files, exports from another z77
 * project). One file per entity class, each a JSON list of record objects.
 * Files are read eagerly on construction so a malformed source fails at the
 * screen, before any plan exists.
 */
final class JsonEntitySource implements ImportSource
{
    /** @var array<class-string, list<array<string, mixed>>> */
    private array $sets = [];

    /**
     * @param array<class-string, string> $files entity class → absolute file path
     */
    public function __construct(array $files, private readonly string $label)
    {
        foreach ($files as $entityClass => $path) {
            $this->sets[$entityClass] = $this->read($entityClass, $path);
        }
    }

    public function recordSets(): array { return $this->sets; }

    public function label(): string { return $this->label; }

    /** @return list<array<string, mixed>> */
    private function read(string $entityClass, string $path): array
    {
        if (!is_readable($path)) {
            throw new ImportSourceException("Source file not readable: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new ImportSourceException("Failed to read source file: {$path}");
        }

        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportSourceException("Malformed JSON in {$path}: {$e->getMessage()}", 0, $e);
        }

        if (!is_array($decoded) || ($decoded !== [] && !array_is_list($decoded))) {
            throw new ImportSourceException(
                "Source for {$entityClass} must be a JSON list of records: {$path}"
            );
        }
        foreach ($decoded as $i => $record) {
            if (!is_array($record)) {
                throw new ImportSourceException(
                    "Record #{$i} for {$entityClass} is not an object: {$path}"
                );
            }
        }

        return $decoded;
    }
}
